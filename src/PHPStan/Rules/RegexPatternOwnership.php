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
 * preg_quote() counts only with its delimiter argument: without it the
 * delimiter is not escaped, and a value holding it ends the pattern early. A
 * local variable is owned when every write to it is, which only the rule can
 * see, so the variables a pattern depends on are returned for it to resolve.
 *
 * @internal
 */
final readonly class RegexPatternOwnership
{
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /** Variables are grouped by the function that holds them; a closure's share its enclosing function's. */
    public static function functionKey(Scope $scope): string
    {
        return ($scope->getClassReflection()?->getName() ?? '') . '::' . ($scope->getFunctionName() ?? '');
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

        return $patternIsKeys ? null : $this->partsDependencies($pattern, $scope);
    }

    /** @return list<string>|null */
    private function partsDependencies(Expr $expr, Scope $scope): ?array
    {
        if ($scope->getType($expr)->isLiteralString()->yes()) {
            return [];
        }

        return match (true) {
            $expr instanceof Variable           => \is_string($expr->name) && !$this->isParameter($expr->name, $scope) ? [$expr->name] : null,
            $expr instanceof Concat             => $this->merged($scope, $expr->left, $expr->right),
            $expr instanceof Ternary            => $this->merged($scope, $expr->if ?? $expr->cond, $expr->else),
            $expr instanceof InterpolatedString => $this->merged(
                $scope,
                ...array_filter($expr->parts, static fn (Expr|InterpolatedStringPart $part): bool => $part instanceof Expr),
            ),
            $expr instanceof FuncCall           => $this->callDependencies($expr, $scope),
            default                             => null,
        };
    }

    /** @return list<string>|null */
    private function callDependencies(FuncCall $call, Scope $scope): ?array
    {
        $args = $call->getArgs();
        if (!$call->name instanceof Name || $call->isFirstClassCallable() || array_any($args, static fn (Arg $arg): bool => $arg->unpack || null !== $arg->name)) {
            return null;
        }

        return match ($this->reflectionProvider->resolveFunctionName($call->name, $scope)) {
            'preg_quote' => 2 === \count($args) ? [] : null,
            'sprintf'    => [] === $args ? null : $this->merged($scope, ...array_map(static fn (Arg $arg): Expr => $arg->value, $args)),
            default      => null,
        };
    }

    /** @return list<string>|null */
    private function merged(Scope $scope, Expr ...$parts): ?array
    {
        $dependencies = [];
        foreach ($parts as $part) {
            $found = $this->partsDependencies($part, $scope);
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
            ...$scope->getFunction()?->getOnlyVariant()->getParameters() ?? [],
            ...$scope->getAnonymousFunctionReflection()?->getParameters() ?? [],
        ];

        return array_any($parameters, static fn (ParameterReflection $parameter): bool => $parameter->getName() === $name);
    }
}
