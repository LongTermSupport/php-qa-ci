<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Collects every write to a local variable, and whether what it writes is a
 * pattern the code owns ({@see RegexPatternOwnership}), so a PCRE call given
 * the variable can be judged by everything the variable could hold.
 *
 * An assignment or a `.=` / `??=` of an owned value is owned, given the local
 * variables it is built from are. A write whose value cannot be read here is
 * not: by reference, by destructuring, by a foreach, by `static` or `global`,
 * by any other compound assignment, or as the argument of a by-reference
 * parameter of a call whose callee can be resolved. `$alias = &$pattern` writes
 * both, since a later write to either changes the other.
 *
 * A write whose target cannot be named here, `$$name = …` or extract(), may
 * write any local, and is recorded against {@see self::ANY_VARIABLE}. A
 * variable passed to a call whose callee cannot be resolved may be written by
 * reference without PHPStan seeing it, so it keeps the literal type it was
 * assigned; that is recorded as a write to {@see self::UNSEEN_WRITE} followed by
 * its name, which the rule holds against any pattern naming the variable.
 *
 * Each value lists [function key, variable name, the local variables the
 * written value is built from, or null when it is not owned] for each variable
 * the node writes.
 *
 * @internal
 *
 * @implements Collector<Node, list<array{string, string, list<string>|null}>>
 */
final readonly class RegexPatternVariableWriteCollector implements Collector
{
    /** Stands for every local: no variable can be named `*`, since `${'*'}` is itself a write to one not named here. */
    public const string ANY_VARIABLE = '*';

    /** Prefixes a variable's name for a write PHPStan's types may not show; no variable name starts with it. */
    public const string UNSEEN_WRITE = '&';

    /** Writes a local for each key of the array it is given, so may write any. */
    private const string EXTRACT = 'extract';

    private RegexPatternOwnership $ownership;

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
        $this->ownership = new RegexPatternOwnership($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<array{string, string, list<string>|null}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $writes = match (true) {
            $node instanceof Assign                                                                                    => $node->var instanceof Variable
                ? $this->write($node->var, $this->ownership->dependencies($node->expr, $scope, false))
                : $this->unownedWrites($node->var),
            $node instanceof AssignOp\Concat, $node instanceof AssignOp\Coalesce                                       => $this->write(
                $node->var,
                $this->ownership->dependencies($node->expr, $scope, false),
            ),
            $node instanceof AssignRef                                                                                 => [...$this->write($node->var, null), ...$this->write($node->expr, null)],
            $node instanceof AssignOp                                                                                  => $this->write($node->var, null),
            $node instanceof Foreach_                                                                                  => [...$this->unownedWrites($node->keyVar), ...$this->unownedWrites($node->valueVar)],
            $node instanceof Static_                                                                                   => array_merge(...array_map(fn (StaticVar $var): array => $this->write($var->var, null), $node->vars)),
            $node instanceof Global_                                                                                   => array_merge(...array_map(fn (Expr $var): array => $this->write($var, null), $node->vars)),
            $node instanceof FuncCall && $node->name instanceof Name && self::EXTRACT === $node->name->toLowerString() => [[self::ANY_VARIABLE, null]],
            $node instanceof CallLike                                                                                  => $this->byReferenceWrites($node, $scope),
            default                                                                                                    => [],
        };
        if ([] === $writes) {
            return null;
        }

        $key = RegexPatternOwnership::functionKey($scope);

        return array_map(static fn (array $write): array => [$key, $write[0], $write[1]], $writes);
    }

    /**
     * @param list<string>|null $dependencies
     *
     * @return list<array{string, list<string>|null}>
     */
    private function write(Expr $var, ?array $dependencies): array
    {
        if (!$var instanceof Variable) {
            return [];
        }

        return \is_string($var->name) ? [[$var->name, $dependencies]] : [[self::ANY_VARIABLE, null]];
    }

    /**
     * Every variable passed to a by-reference parameter of a call whose callee
     * can be resolved: the callee may write anything to it.
     *
     * @return list<array{string, list<string>|null}>
     */
    private function byReferenceWrites(CallLike $call, Scope $scope): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $args     = $call->getArgs();
        $variants = $this->callee($call, $scope)?->getVariants();
        if (null === $variants) {
            return $this->unseenWrites(...$args);
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $args, $variants)->getParameters();
        $writes     = [];
        foreach ($args as $index => $arg) {
            $parameter = $this->parameterFor($arg, $index, ...$parameters);
            if ($parameter instanceof ParameterReflection && !$parameter->passedByReference()->no()) {
                $writes = [...$writes, ...$this->write($arg->value, null)];
            }
        }

        return $writes;
    }

    /**
     * Every variable passed to a call whose callee cannot be resolved, which may take it by reference.
     *
     * @return list<array{string, list<string>|null}>
     */
    private function unseenWrites(Arg ...$args): array
    {
        $writes = [];
        foreach ($args as $arg) {
            foreach ($this->write($arg->value, null) as [$name]) {
                $writes[] = [$name, null];
                if (self::ANY_VARIABLE !== $name) {
                    $writes[] = [self::UNSEEN_WRITE . $name, null];
                }
            }
        }

        return $writes;
    }

    private function callee(CallLike $call, Scope $scope): FunctionReflection|ExtendedMethodReflection|null
    {
        if ($call instanceof New_) {
            if (!$call->class instanceof Name) {
                return null;
            }

            $class = $scope->resolveName($call->class);

            return $this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->hasConstructor()
                ? $this->reflectionProvider->getClass($class)->getConstructor()
                : null;
        }

        if ($call instanceof FuncCall) {
            return $call->name instanceof Name && $this->reflectionProvider->hasFunction($call->name, $scope)
                ? $this->reflectionProvider->getFunction($call->name, $scope)
                : null;
        }

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            return $call->name instanceof Identifier ? $scope->getMethodReflection($scope->getType($call->var), $call->name->toString()) : null;
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
            $class = $scope->resolveName($call->class);

            return $this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->hasMethod($call->name->toString())
                ? $this->reflectionProvider->getClass($class)->getMethod($call->name->toString(), $scope)
                : null;
        }

        return null;
    }

    private function parameterFor(Arg $arg, int $index, ParameterReflection ...$parameters): ?ParameterReflection
    {
        if ($arg->name instanceof Identifier) {
            $name = $arg->name->toString();

            return array_find($parameters, static fn (ParameterReflection $parameter): bool => $parameter->getName() === $name);
        }

        $last = array_last($parameters);

        return $parameters[$index] ?? (null !== $last && $last->isVariadic() ? $last : null);
    }

    /**
     * Every variable a destructuring target writes, or the variable itself.
     *
     * @return list<array{string, list<string>|null}>
     */
    private function unownedWrites(?Expr $target): array
    {
        if (!$target instanceof List_ && !$target instanceof Array_) {
            return $target instanceof Expr ? $this->write($target, null) : [];
        }

        $writes = [];
        foreach ($target->items as $item) {
            if ($item instanceof ArrayItem) {
                $writes = [...$writes, ...$this->unownedWrites($item->value)];
            }
        }

        return $writes;
    }
}
