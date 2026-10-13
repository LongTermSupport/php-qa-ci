<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
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
 * by any other compound assignment, to an offset of the variable, or as the
 * argument of a by-reference parameter of a call whose callee can be resolved.
 *
 * A write whose target cannot be named here, `$$name = …`, extract(), or an
 * `include`, `require` or `eval` running in the caller's scope, may write any
 * local, and is recorded against {@see self::ANY_VARIABLE}. A variable passed
 * to a call whose callee cannot be resolved may be written by reference
 * without PHPStan seeing it, so it keeps the literal type it was assigned; that
 * is recorded as a write to {@see self::UNSEEN_WRITE} followed by its name,
 * which the rule holds against any pattern naming the variable. So is either
 * side of `$alias = &$pattern`, the variable in `[&$pattern]`, and the one a
 * foreach iterates or a destructuring reads with a reference into it: a later
 * write through the alias changes it, wherever that write is made. A copy of
 * such a variable keeps its stale type too, so each write records the
 * variables its value is read from ({@see self::COPIED_FROM}).
 *
 * A script's top-level variables are also written from inside any function:
 * through `$GLOBALS`, or after a `global` declaration. Those writes are keyed
 * {@see self::GLOBAL_SCOPE}, for the rule to hold against top-level code in
 * every file, since the function may be called from any of them.
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

    /** Prefixes a variable's name for a write copying other variables' values into it; no variable name starts with it. */
    public const string COPIED_FROM = '<';

    /** Keys a write to a top-level variable made from any function; it holds no `::`, so no function key equals it. */
    public const string GLOBAL_SCOPE = 'globals';

    /** Writes a local for each key of the array it is given, so may write any. */
    private const string EXTRACT = 'extract';

    /** The superglobal whose offsets are the top-level variables. */
    private const string GLOBALS = 'GLOBALS';

    /** Names whatever class the call is made on, which a subclass may be. */
    private const string LATE_STATIC = 'static';

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
            $node instanceof Assign                                                                                    => [
                ...($node->var instanceof Variable
                    ? $this->write($node->var, $this->ownership->dependencies($node->expr, $scope, false))
                    : $this->unownedWrites($node->var)),
                ...$this->copies($node->var, $node->expr, $scope),
                ...($this->destructuresByReference($node->var) ? $this->unseenWrites($node->expr) : []),
            ],
            $node instanceof AssignOp\Concat, $node instanceof AssignOp\Coalesce                                       => [
                ...$this->write($node->var, $this->ownership->dependencies($node->expr, $scope, false)),
                ...$this->copies($node->var, $node->expr, $scope),
            ],
            $node instanceof AssignRef                                                                                 => $this->unseenWrites($node->var, $node->expr),
            $node instanceof AssignOp                                                                                  => [...$this->write($node->var, null), ...$this->copies($node->var, $node->expr, $scope)],
            $node instanceof Foreach_                                                                                  => [
                ...$this->unownedWrites($node->keyVar),
                ...$this->unownedWrites($node->valueVar),
                ...$this->copies($node->keyVar, $node->expr, $scope),
                ...$this->copies($node->valueVar, $node->expr, $scope),
                ...($node->byRef || $this->destructuresByReference($node->valueVar) ? $this->unseenWrites($node->expr) : []),
            ],
            $node instanceof Static_                                                                                   => array_merge(...array_map(fn (StaticVar $var): array => $this->write($var->var, null), $node->vars)),
            $node instanceof Global_                                                                                   => array_merge(...array_map($this->globalWrites(...), $node->vars)),
            $node instanceof ArrayItem                                                                                 => $node->byRef ? $this->unseenWrites($node->value) : [],
            $node instanceof Include_, $node instanceof Eval_                                                          => [[self::ANY_VARIABLE, null, null]],
            $node instanceof FuncCall && $node->name instanceof Name && self::EXTRACT === $node->name->toLowerString() => [[self::ANY_VARIABLE, null, null]],
            $node instanceof CallLike                                                                                  => $this->byReferenceWrites($node, $scope),
            default                                                                                                    => [],
        };
        if ([] === $writes) {
            return null;
        }

        $key = RegexPatternOwnership::functionKey($scope);

        return array_map(static fn (array $write): array => [$write[2] ?? $key, $write[0], $write[1]], $writes);
    }

    /**
     * Each write is [variable name, dependencies, the key it is made under, or null for the function's own].
     *
     * @param list<string>|null $dependencies
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function write(Expr $var, ?array $dependencies): array
    {
        if ($var instanceof ArrayDimFetch) {
            return $this->offsetWrite($var);
        }

        if (!$var instanceof Variable) {
            return [];
        }

        return \is_string($var->name) ? [[$var->name, $dependencies, null]] : [[self::ANY_VARIABLE, null, null]];
    }

    /**
     * Writing an offset changes the variable holding it, a string in place
     * included; through `$GLOBALS`, the top-level variable the offset names.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function offsetWrite(ArrayDimFetch $fetch): array
    {
        if ($fetch->var instanceof Variable && self::GLOBALS === $fetch->var->name) {
            return $this->topLevelWrites($fetch->dim instanceof String_ ? $fetch->dim->value : self::ANY_VARIABLE);
        }

        return $this->write($fetch->var, null);
    }

    /**
     * `global $name` binds the local to the top-level variable, so a later
     * write to either is a write to both.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function globalWrites(Expr $var): array
    {
        $writes = [];
        foreach ($this->write($var, null) as [$name]) {
            $writes = [...$writes, [$name, null, null], ...$this->topLevelWrites($name)];
        }

        return $writes;
    }

    /**
     * A write to a top-level variable from elsewhere, which PHPStan's type for
     * it at the top level does not show.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function topLevelWrites(string $name): array
    {
        return self::ANY_VARIABLE === $name
            ? [[$name, null, self::GLOBAL_SCOPE]]
            : [[$name, null, self::GLOBAL_SCOPE], [self::UNSEEN_WRITE . $name, null, self::GLOBAL_SCOPE]];
    }

    /**
     * Every variable passed to a by-reference parameter of a call whose callee
     * can be resolved: the callee may write anything to it.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function byReferenceWrites(CallLike $call, Scope $scope): array
    {
        if ($call->isFirstClassCallable() || $this->constructsWithoutAConstructor($call, $scope)) {
            return [];
        }

        $args     = $call->getArgs();
        $variants = $this->callee($call, $scope)?->getVariants();
        if (null === $variants) {
            return $this->unseenWrites(...array_map(static fn (Arg $arg): Expr => $arg->value, $args));
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
     * Every variable a later write may change where PHPStan does not follow it:
     * one passed to a call whose callee cannot be resolved, which may take it by
     * reference, and either side of a reference, written through the other.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function unseenWrites(Expr ...$vars): array
    {
        $writes = [];
        foreach ($vars as $var) {
            foreach ($this->write($var, null) as [$name, , $key]) {
                $writes[] = [$name, null, $key];
                if (self::ANY_VARIABLE !== $name) {
                    $writes[] = [self::UNSEEN_WRITE . $name, null, $key];
                }
            }
        }

        return $writes;
    }

    /** A class with no constructor has no parameter to take anything by reference; `new static` may build a subclass that has one. */
    private function constructsWithoutAConstructor(CallLike $call, Scope $scope): bool
    {
        if (!$call instanceof New_ || !$call->class instanceof Name || self::LATE_STATIC === $call->class->toLowerString()) {
            return false;
        }

        $class = $scope->resolveName($call->class);

        return $this->reflectionProvider->hasClass($class) && !$this->reflectionProvider->getClass($class)->hasConstructor();
    }

    private function callee(CallLike $call, Scope $scope): FunctionReflection|ExtendedMethodReflection|null
    {
        if ($call instanceof New_) {
            if (!$call->class instanceof Name || self::LATE_STATIC === $call->class->toLowerString()) {
                return null;
            }

            $class = $scope->resolveName($call->class);

            return $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class)->getConstructor() : null;
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
     * A write of a value read from other variables, recorded as a write to
     * {@see self::COPIED_FROM} followed by each target's name, listing them: a
     * copy holds what an unseen write left in its source, whatever type PHPStan
     * gives it. A value preg_quote() is given is not among them; what it returns
     * is matched literally whatever that holds.
     *
     * @return list<array{string, list<string>|null, string|null}>
     */
    private function copies(?Expr $target, Expr $source, Scope $scope): array
    {
        $sources = $this->ownership->variablesNamed($source, $scope);
        if ([] === $sources) {
            return [];
        }

        $copies = [];
        foreach ($this->unownedWrites($target) as [$name, , $key]) {
            $copies[] = [self::COPIED_FROM . $name, $sources, $key];
        }

        return $copies;
    }

    /** A destructuring target holding `&$item` at any depth takes a reference into the value it destructures. */
    private function destructuresByReference(?Expr $target): bool
    {
        if (!$target instanceof List_ && !$target instanceof Array_) {
            return false;
        }

        return array_any(
            $target->items,
            fn (?ArrayItem $item): bool => $item instanceof ArrayItem && ($item->byRef || $this->destructuresByReference($item->value)),
        );
    }

    /**
     * Every variable a destructuring target writes, or the variable itself.
     *
     * @return list<array{string, list<string>|null, string|null}>
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
