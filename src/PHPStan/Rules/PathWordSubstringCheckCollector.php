<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * Collects, per function, each search for a bare word in a string that may be an absolute path, and
 * each write to a local variable with whether it writes a path.
 *
 * A search is collected when the function is one of {@see self::SEARCH_FUNCTIONS}, its needle is, or
 * may be, a bare word ({@see self::WORD_PATTERN}), and its haystack is either a local variable that
 * is not a parameter, recorded by name for {@see ForbidPathWordSubstringCheckRule} to judge by its
 * writes, with whether its name says it is a path; or another expression known to be a path
 * ({@see AbsolutePathExpression}), recorded with a null variable.
 *
 * A write is an assignment, recorded as a path when {@see AbsolutePathExpression} says its value is
 * one; or a `??=` of a value, judged the same way. Every other write to a variable is recorded as not
 * a path: by reference, by destructuring, by a foreach, by `static` or `global`, and by a compound
 * assignment other than `.=`, which appends to whatever the variable held and so keeps it a path.
 *
 * Each value is [function key, searches as [variable or null, line, function, printed haystack,
 * word, haystack known to be a path], writes as [variable, is a path]]. The function key names the
 * class and function the node is in; a closure's is its enclosing function's.
 *
 * @internal
 *
 * @implements Collector<Node, array{string, list<array{string|null, int, string, string, string, bool}>, list<array{string, bool}>}>
 */
final readonly class PathWordSubstringCheckCollector implements Collector
{
    /** @var list<string> functions that find a needle anywhere in a haystack, taking (haystack, needle) */
    private const array SEARCH_FUNCTIONS = [
        'str_contains', 'strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr',
        'mb_strpos', 'mb_stripos', 'mb_strrpos', 'mb_strripos', 'mb_strstr', 'mb_stristr',
    ];

    /** @var string a needle with no separator or dot in it, and at least one letter */
    private const string WORD_PATTERN = '~^[\w-]*[A-Za-z][\w-]*$~';

    /** @var string the haystack parameter's name, for a call with named arguments */
    private const string HAYSTACK = 'haystack';

    /** @var string the needle parameter's name, for a call with named arguments */
    private const string NEEDLE = 'needle';

    private AbsolutePathExpression $paths;

    public function __construct()
    {
        $this->paths = new AbsolutePathExpression();
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return array{string, list<array{string|null, int, string, string, string, bool}>, list<array{string, bool}>}|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node instanceof FuncCall) {
            $search = $this->search($node, $scope);

            return null === $search ? null : [$this->functionKey($scope), [$search], []];
        }

        $writes = $this->writes($node, $scope);

        return [] === $writes ? null : [$this->functionKey($scope), [], $writes];
    }

    /** @return array{string|null, int, string, string, string, bool}|null */
    private function search(FuncCall $call, Scope $scope): ?array
    {
        if (!$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $function = $call->name->toLowerString();
        if (!\in_array($function, self::SEARCH_FUNCTIONS, true)) {
            return null;
        }

        $haystack = $this->argument($call, 0, self::HAYSTACK);
        $needle   = $this->argument($call, 1, self::NEEDLE);
        if (!$haystack instanceof Expr || !$needle instanceof Expr) {
            return null;
        }

        $word = $this->word($needle, $scope);
        if (null === $word) {
            return null;
        }

        $printed = new Standard()->prettyPrintExpr($haystack);
        $isPath  = $this->paths->isPath($haystack, $scope);
        if ($haystack instanceof Variable && \is_string($haystack->name) && !$this->isParameter($haystack->name, $scope)) {
            return [$haystack->name, $call->getStartLine(), $function, $printed, $word, $isPath];
        }

        if ($isPath) {
            return [null, $call->getStartLine(), $function, $printed, $word, $isPath];
        }

        return null;
    }

    private function argument(FuncCall $call, int $position, string $name): ?Expr
    {
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->name instanceof Node\Identifier ? $arg->name->toString() === $name : $index === $position) {
                return $arg->value;
            }
        }

        return null;
    }

    private function word(Expr $needle, Scope $scope): ?string
    {
        $values = $needle instanceof String_
            ? [$needle->value]
            : array_map(
                static fn (ConstantStringType $string): string => $string->getValue(),
                $scope->getType($needle)->getConstantStrings(),
            );
        foreach ($values as $value) {
            if (1 === \Safe\preg_match(self::WORD_PATTERN, $value)) {
                return $value;
            }
        }

        return null;
    }

    private function isParameter(string $variable, Scope $scope): bool
    {
        $parameters = $scope->getAnonymousFunctionReflection()?->getParameters()
            ?? $scope->getFunction()?->getOnlyVariant()->getParameters()
            ?? [];

        return \in_array(
            $variable,
            array_map(static fn (ParameterReflection $parameter): string => $parameter->getName(), $parameters),
            true,
        );
    }

    /** @return list<array{string, bool}> */
    private function writes(Node $node, Scope $scope): array
    {
        return match (true) {
            $node instanceof Assign && $node->var instanceof Variable                      => $this->named($node->var, $this->paths->isPath($node->expr, $scope)),
            $node instanceof Assign                                                        => $this->variablesIn($node->var),
            $node instanceof AssignOp\Concat                                               => [],
            $node instanceof AssignOp\Coalesce && $node->var instanceof Variable           => $this->named($node->var, $this->paths->isPath($node->expr, $scope)),
            $node instanceof AssignOp                                                      => $this->variablesIn($node->var),
            $node instanceof Foreach_                                                      => $this->variablesIn(...array_filter([$node->keyVar, $node->valueVar])),
            $node instanceof AssignRef, $node instanceof Static_, $node instanceof Global_ => $this->variablesIn($node),
            default                                                                        => [],
        };
    }

    /** @return list<array{string, bool}> */
    private function named(Variable $variable, bool $isPath): array
    {
        return \is_string($variable->name) ? [[$variable->name, $isPath]] : [];
    }

    /** @return list<array{string, bool}> each variable named in the nodes, written with something that is not known to be a path */
    private function variablesIn(Node ...$nodes): array
    {
        return array_merge(...array_map(
            fn (Variable $variable): array => $this->named($variable, false),
            new NodeFinder()->findInstanceOf($nodes, Variable::class),
        ));
    }

    /** The class and function the scope is in; either part is empty outside one, which is a scope of its own. */
    private function functionKey(Scope $scope): string
    {
        return \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunction()?->getName());
    }
}
