<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Whether the code owns a regular expression pattern: literal-string typed, or
 * built from literal strings, preg_quote() results and owned local variables,
 * joined by concatenation, interpolation, a ternary or sprintf(). A pattern
 * so built compiles exactly when its literal parts do, which its tests prove.
 *
 * preg_quote() counts only when given a delimiter, and, where the pattern's own
 * delimiter can be read from its leftmost literal, that one: with none, or another,
 * the delimiter is not escaped, and a value holding it ends the pattern early. A
 * local variable is owned when every write to it is, which only the rule can
 * see, so the variables a pattern depends on are returned for it to resolve.
 *
 * @internal
 */
final readonly class RegexPatternOwnership
{
    /** Each bracket delimiter => the one that closes the pattern. */
    private const array CLOSING_DELIMITERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /** Variables are grouped by the function that holds them; a closure's share its enclosing function's. */
    public static function functionKey(Scope $scope): string
    {
        return \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunctionName());
    }

    /**
     * The local variables an owned pattern is built from, each owned only if
     * every write to it is; null when the code does not own the pattern.
     *
     * @return list<string>|null
     */
    public function dependencies(Expr $pattern, Scope $scope, bool $patternIsKeys): ?array
    {
        $type = $scope->getType($pattern);
        if ($type->isArray()->yes()) {
            return ($patternIsKeys ? $type->getIterableKeyType() : $type->getIterableValueType())->isLiteralString()->yes() ? [] : null;
        }

        return $patternIsKeys ? null : $this->partsDependencies($pattern, $scope, $this->delimiter($pattern, $scope));
    }

    /**
     * The pattern's opening delimiter: the first character of its leftmost literal
     * part, or null when the pattern does not start with one.
     */
    private function delimiter(Expr $expr, Scope $scope): ?string
    {
        $constants = $scope->getType($expr)->getConstantStrings();
        if (1 === \count($constants)) {
            return '' === $constants[0]->getValue() ? null : $constants[0]->getValue()[0];
        }

        $first = $expr instanceof InterpolatedString ? ($expr->parts[0] ?? null) : null;

        return match (true) {
            $expr instanceof Concat                  => $this->delimiter($expr->left, $scope),
            $first instanceof InterpolatedStringPart => '' === $first->value ? null : $first->value[0],
            $first instanceof Expr                   => $this->delimiter($first, $scope),
            $expr instanceof Ternary                 => $this->sameDelimiter($scope, $expr->if ?? $expr->cond, $expr->else),
            $expr instanceof FuncCall                => $this->sprintfDelimiter($expr, $scope),
            default                                  => null,
        };
    }

    private function sameDelimiter(Scope $scope, Expr $one, Expr $other): ?string
    {
        $delimiter = $this->delimiter($one, $scope);

        return $delimiter === $this->delimiter($other, $scope) ? $delimiter : null;
    }

    private function sprintfDelimiter(FuncCall $call, Scope $scope): ?string
    {
        $format = $call->getArgs()[0] ?? null;
        if (!$call->name instanceof Name || !$format instanceof Arg || 'sprintf' !== $this->reflectionProvider->resolveFunctionName($call->name, $scope)) {
            return null;
        }

        return $this->delimiter($format->value, $scope);
    }

    /** @return list<string>|null */
    private function partsDependencies(Expr $expr, Scope $scope, ?string $delimiter): ?array
    {
        if ($scope->getType($expr)->isLiteralString()->yes()) {
            return [];
        }

        return match (true) {
            $expr instanceof Variable           => \is_string($expr->name) && !$this->isParameter($expr->name, $scope) ? [$expr->name] : null,
            $expr instanceof Concat             => $this->merged($scope, $delimiter, $expr->left, $expr->right),
            $expr instanceof Ternary            => $this->merged($scope, $delimiter, $expr->if ?? $expr->cond, $expr->else),
            $expr instanceof InterpolatedString => $this->merged(
                $scope,
                $delimiter,
                ...array_filter($expr->parts, static fn (Expr|InterpolatedStringPart $part): bool => $part instanceof Expr),
            ),
            $expr instanceof FuncCall           => $this->callDependencies($expr, $scope, $delimiter),
            default                             => null,
        };
    }

    /** @return list<string>|null */
    private function callDependencies(FuncCall $call, Scope $scope, ?string $delimiter): ?array
    {
        $args = $call->getArgs();
        if (!$call->name instanceof Name || $call->isFirstClassCallable() || array_any($args, static fn (Arg $arg): bool => $arg->unpack || $arg->name instanceof \PhpParser\Node\Identifier)) {
            return null;
        }

        return match ($this->reflectionProvider->resolveFunctionName($call->name, $scope)) {
            'preg_quote' => $this->quotesDelimiter($scope, $delimiter, ...$args) ? [] : null,
            'sprintf'    => [] === $args ? null : $this->merged($scope, $delimiter, ...array_map(static fn (Arg $arg): Expr => $arg->value, $args)),
            default      => null,
        };
    }

    /**
     * preg_quote() escapes the pattern's delimiter only when it is given it, or the
     * closing one of a bracket pair, which ends the pattern. A fragment quoted on its
     * own, with no delimiter beside it, is checked only for being given one.
     */
    private function quotesDelimiter(Scope $scope, ?string $delimiter, Arg ...$args): bool
    {
        if (2 !== \count($args)) {
            return false;
        }

        $given = $scope->getType($args[1]->value)->getConstantStrings();
        if (1 !== \count($given)) {
            return false;
        }

        return null === $delimiter || \in_array($given[0]->getValue(), [$delimiter, self::CLOSING_DELIMITERS[$delimiter] ?? $delimiter], true);
    }

    /** @return list<string>|null */
    private function merged(Scope $scope, ?string $delimiter, Expr ...$parts): ?array
    {
        $dependencies = [];
        foreach ($parts as $part) {
            $found = $this->partsDependencies($part, $scope, $delimiter);
            if (null === $found) {
                return null;
            }

            $dependencies = [...$dependencies, ...$found];
        }

        return array_values(array_unique($dependencies));
    }

    /** A parameter is never owned: its caller chose it. */
    private function isParameter(string $name, Scope $scope): bool
    {
        $parameters = [
            ...$scope->getFunction()?->getOnlyVariant()->getParameters()  ?? [],
            ...$scope->getAnonymousFunctionReflection()?->getParameters() ?? [],
        ];

        return array_any($parameters, static fn (ParameterReflection $parameter): bool => $parameter->getName() === $name);
    }
}
