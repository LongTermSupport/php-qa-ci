<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Collects what ForbidNumericStringArrayKeyRule joins: each write of an array
 * element under a key that may be an all-digit string, each place an array is
 * taken as string-keyed, each closure's span, and each foreach over an array
 * whose key variable its body leaves alone.
 *
 * PHP stores a key that is a decimal integer string ('2024', '-1', not '01')
 * as an int, but PHPStan gives `$map[$string] = …` the key type string. A key
 * may be all digits when PHPStan types it a string that is not a class-string,
 * does not already count the int (it does for a numeric-string), does not rule
 * it out, and no literal part of the expression holds a character other than a
 * digit or a minus sign.
 *
 * A write into a property is judged here, against the property's declared
 * type at the depth of the key. A write into a local variable is recorded with
 * the variable's name, the key's depth and, when the key is a variable, its
 * name; the variable is taken as string-keyed where it is returned, passed as
 * an argument or assigned to a property whose declared type has string keys at
 * that depth, and where foreach, a function that reads keys, array_merge() or
 * spreading reads its keys while PHPStan types them string.
 *
 * @internal
 *
 * @phpstan-type NumericStringArrayKeyRecord array{kind: string, functionKey: string, variable: string, key: string, depths: list<int>, line: int, start: int, end: int, text: string, uses: list<string>}
 *
 * @implements Collector<Node, list<NumericStringArrayKeyRecord>>
 */
final readonly class NumericStringArrayKeyCollector implements Collector
{
    /** A key that may be all digits written into a local variable, joined with a use of the variable. */
    public const string WRITE = 'write';

    /** A key that may be all digits written into a property declared string-keyed: reported as it stands. */
    public const string DECLARED = 'declared';

    /** A place a local variable is taken as string-keyed. */
    public const string USE = 'use';

    /** A closure: its own local variables, other than those it imports, are not the enclosing function's. */
    public const string CLOSURE = 'closure';

    /**
     * A foreach over an array whose key variable nothing in the loop writes:
     * in the loop the variable holds a key PHP made, and a string PHP leaves
     * as a key is never all digits.
     */
    public const string KEY_BINDING = 'keyBinding';

    /** `$copy = $array`: whatever takes the copy as string-keyed takes the array so. The copy's name is the record's key. */
    public const string COPY = 'copy';

    /** How deep a declared type is searched for string keys. */
    private const int MAX_DEPTH = 8;

    /** The only characters of a decimal integer string. */
    private const string DECIMAL_INTEGER_CHARACTERS = '-0123456789';

    /** Names whatever class the call is made on, which a subclass may be. */
    private const string LATE_STATIC = 'static';

    /**
     * Functions that hand an array's keys to code, or renumber its int keys,
     * => the positions of the arguments whose keys they read; null for every
     * argument.
     */
    private const array KEY_READING_FUNCTIONS = [
        'array_keys'            => [0],
        'array_key_first'       => [0],
        'array_key_last'        => [0],
        'key'                   => [0],
        'array_flip'            => [0],
        'array_search'          => [1],
        'array_find_key'        => [0],
        'array_find'            => [0],
        'array_any'             => [0],
        'array_all'             => [0],
        'array_walk'            => [0],
        'array_walk_recursive'  => [0],
        'uksort'                => [0],
        'array_merge'           => null,
        'array_merge_recursive' => null,
    ];

    /** array_filter() hands the callback keys only when given a mode, its third argument. */
    private const string ARRAY_FILTER = 'array_filter';

    /** The number of arguments with which array_filter() is given a mode. */
    private const int ARRAY_FILTER_WITH_MODE = 3;

    /** The names PHP gives the array these functions read. */
    private const array KEY_READING_PARAMETERS = ['array', 'haystack'];

    /** What a nested key's target adds per level. */
    private const string NESTED = '[…]';

    /** How a spread array's keys are read. */
    private const string SPREADS = 'spreads it';

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<NumericStringArrayKeyRecord>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $records = match (true) {
            $node instanceof Closure                                     => [$this->closure($node, $scope)],
            $node instanceof Assign                                      => [...$this->writes($node, $node->var, $scope), ...$this->propertyAssignment($node, $scope), ...$this->copy($node, $scope)],
            $node instanceof AssignOp, $node instanceof AssignRef,
            $node instanceof PreInc, $node instanceof PreDec,
            $node instanceof PostInc, $node instanceof PostDec           => $this->writes($node, $node->var, $scope),
            $node instanceof Return_                                     => $this->returned($node, $scope),
            $node instanceof Foreach_                                    => $this->iterated($node, $scope),
            $node instanceof ArrayItem && $node->unpack                  => $this->keysRead($node, $node->value, self::SPREADS, $scope),
            $node instanceof CallLike  && !$node->isFirstClassCallable() => $this->passed($node, $scope),
            default                                                      => [],
        };

        return [] === $records ? null : $records;
    }

    /**
     * @param list<int>    $depths
     * @param list<string> $uses
     *
     * @return NumericStringArrayKeyRecord
     */
    private function record(string $kind, Scope $scope, Node $node, string $variable = '', array $depths = [], string $text = '', array $uses = [], string $key = ''): array
    {
        return [
            'kind'        => $kind,
            'functionKey' => \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunctionName()),
            'variable'    => $variable,
            'key'         => $key,
            'depths'      => $depths,
            'line'        => $node->getStartLine(),
            'start'       => $node->getStartFilePos(),
            'end'         => $node->getEndFilePos(),
            'text'        => $text,
            'uses'        => $uses,
        ];
    }

    /**
     * @return NumericStringArrayKeyRecord
     */
    private function closure(Closure $closure, Scope $scope): array
    {
        $uses = [];
        foreach ($closure->uses as $use) {
            if (\is_string($use->var->name)) {
                $uses[] = $use->var->name;
            }
        }

        return $this->record(self::CLOSURE, $scope, $closure, uses: $uses);
    }

    /**
     * Each key of the written element that may be all digits: recorded against
     * a local variable, or against a property declared string-keyed at its depth.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function writes(Expr $write, Expr $target, Scope $scope): array
    {
        $keys = [];
        $root = $target;
        while ($root instanceof ArrayDimFetch) {
            array_unshift($keys, $root->dim);
            $root = $root->var;
        }

        $records = [];
        foreach ($keys as $depth => $key) {
            if (!$key instanceof Expr || !$this->mayBeAllDigits($key, $scope)) {
                continue;
            }

            $keyName = $key instanceof Variable && \is_string($key->name) ? $key->name : '';
            if ($root instanceof Variable && \is_string($root->name)) {
                $records[] = $this->record(self::WRITE, $scope, $write, $root->name, [$depth], '$' . $root->name . str_repeat(self::NESTED, $depth), key: $keyName);

                continue;
            }

            $declared = $this->declaredPropertyType($root, $scope);
            if ($declared instanceof Type && \in_array($depth, $this->stringKeyedDepths($declared), true)) {
                $records[] = $this->record(
                    self::DECLARED,
                    $scope,
                    $write,
                    $this->propertyName($root) . str_repeat(self::NESTED, $depth),
                    [$depth],
                    'it is declared ' . $declared->describe(VerbosityLevel::typeOnly()),
                    key: $keyName,
                );
            }
        }

        return $records;
    }

    /** Whether PHP may store the key as an int where PHPStan types it string. */
    private function mayBeAllDigits(Expr $key, Scope $scope): bool
    {
        $type = $scope->getType($key);

        return $type->isString()->yes()
            && !$type->isDecimalIntegerString()->no()
            && !$type->isClassString()->yes()
            && $type->toArrayKey()->isInteger()->no()
            && !$this->holdsANonDigit($key, $scope);
    }

    /** Whether some literal part of the expression holds a character no decimal integer string has. */
    private function holdsANonDigit(Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof Concat) {
            return $this->holdsANonDigit($expr->left, $scope) || $this->holdsANonDigit($expr->right, $scope);
        }

        if ($expr instanceof InterpolatedString) {
            return array_any(
                $expr->parts,
                fn (Expr|InterpolatedStringPart $part): bool => $part instanceof InterpolatedStringPart ? $this->isNotDecimal($part->value) : $this->holdsANonDigit($part, $scope),
            );
        }

        $constants = $scope->getType($expr)->getConstantStrings();

        return [] !== $constants && array_all($constants, fn (ConstantStringType $constant): bool => $this->isNotDecimal($constant->getValue()));
    }

    private function isNotDecimal(string $value): bool
    {
        return \strlen($value) !== strspn($value, self::DECIMAL_INTEGER_CHARACTERS);
    }

    private function declaredPropertyType(Expr $property, Scope $scope): ?Type
    {
        if ($property instanceof PropertyFetch && $property->name instanceof Identifier) {
            return $scope->getInstancePropertyReflection($scope->getType($property->var), $property->name->toString())?->getWritableType();
        }

        if ($property instanceof StaticPropertyFetch && $property->name instanceof VarLikeIdentifier && $property->class instanceof Name) {
            return $scope->getStaticPropertyReflection($scope->resolveTypeByName($property->class), $property->name->toString())?->getWritableType();
        }

        return null;
    }

    private function propertyName(Expr $property): string
    {
        if ($property instanceof PropertyFetch && $property->name instanceof Identifier) {
            return ($property->var instanceof Variable && \is_string($property->var->name) ? '$' . $property->var->name : '…') . '->' . $property->name->toString();
        }

        if ($property instanceof StaticPropertyFetch && $property->name instanceof VarLikeIdentifier && $property->class instanceof Name) {
            return $property->class->toString() . '::$' . $property->name->toString();
        }

        return '…';
    }

    /**
     * The depths at which a type's keys are all strings: 0 for its own keys,
     * 1 for those of the arrays it holds, and so on.
     *
     * @return list<int>
     */
    private function stringKeyedDepths(Type $type): array
    {
        $depths = [];
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $type = TypeCombinator::removeNull($type);
            if (!$type->isIterable()->yes() || $type->isIterableAtLeastOnce()->no()) {
                break;
            }

            if ($type->getIterableKeyType()->isString()->yes()) {
                $depths[] = $depth;
            }

            $type = $type->getIterableValueType();
        }

        return $depths;
    }

    /**
     * A local variable, or an element of one, as [its name, the depth of the
     * element]; null for anything else.
     *
     * @return array{string, int}|null
     */
    private function localArray(Expr $expr): ?array
    {
        $depth = 0;
        while ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr) {
            $expr = $expr->var;
            ++$depth;
        }

        return $expr instanceof Variable && \is_string($expr->name) ? [$expr->name, $depth] : null;
    }

    /**
     * A local array handed to a place whose declared type has string keys.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function declaredUse(Node $node, Expr $value, Type $declared, string $text, Scope $scope): array
    {
        $local  = $this->localArray($value);
        $depths = $this->stringKeyedDepths($declared);
        if (null === $local || [] === $depths) {
            return [];
        }

        return [$this->record(
            self::USE,
            $scope,
            $node,
            $local[0],
            array_map(static fn (int $depth): int => $depth + $local[1], $depths),
            $text . ' ' . $declared->describe(VerbosityLevel::typeOnly()),
        )];
    }

    /**
     * A local array whose keys code reads while PHPStan types them string.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysRead(Node $node, Expr $value, string $text, Scope $scope): array
    {
        $local = $this->localArray($value);
        if (null === $local || !\in_array(0, $this->stringKeyedDepths($scope->getType($value)), true)) {
            return [];
        }

        return [$this->record(self::USE, $scope, $node, $local[0], [$local[1]], $text)];
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function propertyAssignment(Assign $assign, Scope $scope): array
    {
        $declared = $this->declaredPropertyType($assign->var, $scope);

        return $declared instanceof Type
            ? $this->declaredUse($assign, $assign->expr, $declared, 'assigns it to ' . $this->propertyName($assign->var) . ', declared', $scope)
            : [];
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function copy(Assign $assign, Scope $scope): array
    {
        if (!$assign->var instanceof Variable || !\is_string($assign->var->name) || !$assign->expr instanceof Variable
                                              || !\is_string($assign->expr->name) || $assign->var->name === $assign->expr->name) {
            return [];
        }

        return [$this->record(self::COPY, $scope, $assign, $assign->expr->name, key: $assign->var->name)];
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function returned(Return_ $return, Scope $scope): array
    {
        if (!$return->expr instanceof Expr) {
            return [];
        }

        if ($scope->isInAnonymousFunction()) {
            $declared = $scope->getAnonymousFunctionReturnType();
        } else {
            $function = $scope->getFunction();
            $declared = !$function instanceof \PHPStan\Reflection\Php\PhpFunctionFromParserNodeReflection || $function->isGenerator() ? null : $function->getReturnType();
        }

        return $declared instanceof Type ? $this->declaredUse($return, $return->expr, $declared, 'returns it as', $scope) : [];
    }

    /**
     * A foreach reads the keys of what it iterates; over an array, its key
     * variable holds a key PHP made, for as long as the loop body leaves it alone.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function iterated(Foreach_ $foreach, Scope $scope): array
    {
        if (!$foreach->keyVar instanceof Expr) {
            return [];
        }

        $records = $this->keysRead($foreach, $foreach->expr, 'reads its keys with foreach', $scope);
        if ($foreach->keyVar instanceof Variable && \is_string($foreach->keyVar->name) && $scope->getType($foreach->expr)->isArray()->yes()
                                                 && !$this->mayRebind($foreach->keyVar->name, $scope, ...$foreach->stmts)) {
            $records[] = $this->record(self::KEY_BINDING, $scope, $foreach, $foreach->keyVar->name);
        }

        return $records;
    }

    /**
     * Whether the statements may write the variable: assign to it, destructure
     * into it, pass it to a call other than a function taking it by value,
     * iterate into it, declare it static or global, unset it, take it as a
     * parameter, take a reference, or write a variable that cannot be named.
     */
    private function mayRebind(string $name, Scope $scope, Node ...$statements): bool
    {
        $isNamed = static fn (?Node $node): bool => $node instanceof Variable && $name === $node->name;

        return new NodeFinder()->findFirst($statements, fn (Node $node): bool => match (true) {
            $node instanceof Variable                                                      => !\is_string($node->name),
            $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => $isNamed($node->var) || $node->var instanceof List_ || $node->var instanceof Expr\Array_,
            $node instanceof ArrayItem                                                     => $node->byRef,
            $node instanceof CallLike                                                      => $this->mayWriteArgument($node, $name, $scope),
            $node instanceof Foreach_                                                      => $isNamed($node->keyVar) || $isNamed($node->valueVar) || !$node->valueVar instanceof Variable,
            $node instanceof StaticVar                                                     => $isNamed($node->var),
            $node instanceof Global_, $node instanceof Unset_                              => array_any($node->vars, $isNamed),
            $node instanceof Param                                                         => $isNamed($node->var),
            default                                                                        => false,
        }) instanceof Node;
    }

    /**
     * Whether a call may write the named variable passed to it: any call but
     * one to a function that takes it by value, and extract(), which writes any.
     */
    private function mayWriteArgument(CallLike $call, string $name, Scope $scope): bool
    {
        if ($call instanceof FuncCall && $call->name instanceof Name && 'extract' === $call->name->toLowerString()) {
            return true;
        }

        if ($call->isFirstClassCallable()) {
            return false;
        }

        $args   = $call->getArgs();
        $passed = array_filter($args, static fn (Arg $arg): bool => $arg->value instanceof Variable && $name === $arg->value->name);
        if ([] === $passed) {
            return false;
        }

        if (!$call instanceof FuncCall || !$call->name instanceof Name || !$this->reflectionProvider->hasFunction($call->name, $scope)) {
            return true;
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $args, $this->reflectionProvider->getFunction($call->name, $scope)->getVariants())->getParameters();
        foreach ($passed as $index => $arg) {
            $parameter = $this->parameterFor($arg, $index, ...$parameters);
            if (!$parameter instanceof ParameterReflection || !$parameter->passedByReference()->no()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A local array spread into a call, passed to a function that reads its
     * keys, or passed to a parameter declared string-keyed.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function passed(CallLike $call, Scope $scope): array
    {
        $args    = $call->getArgs();
        $records = [];
        foreach ($args as $arg) {
            if ($arg->unpack) {
                $records = [...$records, ...$this->keysRead($call, $arg->value, self::SPREADS, $scope)];
            }
        }

        if ($call instanceof FuncCall && $call->name instanceof Name) {
            $records = [...$records, ...$this->keysReadBy($call->name->toString(), $scope, $call, ...$args)];
        }

        $callee = $this->callee($call, $scope);
        if (null === $callee) {
            return $records;
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $args, $callee->getVariants())->getParameters();
        foreach ($args as $index => $arg) {
            $parameter = $arg->unpack ? null : $this->parameterFor($arg, $index, ...$parameters);
            if ($parameter instanceof ParameterReflection) {
                $records = [...$records, ...$this->declaredUse($call, $arg->value, $parameter->getType(), 'passes it to ' . $this->calleeName($call) . ' as', $scope)];
            }
        }

        return $records;
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysReadBy(string $function, Scope $scope, FuncCall $call, Arg ...$args): array
    {
        $lower = strtolower(ltrim($function, '\\'));
        if (\array_key_exists($lower, self::KEY_READING_FUNCTIONS)) {
            $positions = self::KEY_READING_FUNCTIONS[$lower];
        } elseif (self::ARRAY_FILTER === $lower && \count($args) >= self::ARRAY_FILTER_WITH_MODE) {
            $positions = [0];
        } else {
            return [];
        }

        $records = [];
        foreach ($args as $index => $arg) {
            $named = $arg->name instanceof Identifier && \in_array($arg->name->toString(), self::KEY_READING_PARAMETERS, true);
            if (!$arg->unpack && (null === $positions || $named || (!$arg->name instanceof Identifier && \in_array($index, $positions, true)))) {
                $records = [...$records, ...$this->keysRead($call, $arg->value, 'reads its keys with ' . $function . '()', $scope)];
            }
        }

        return $records;
    }

    private function calleeName(CallLike $call): string
    {
        if ($call instanceof New_) {
            return $call->class instanceof Name ? 'new ' . $call->class->getLast() . '()' : 'a constructor';
        }

        if ($call instanceof FuncCall) {
            return $call->name instanceof Name ? $call->name->toString() . '()' : 'a function';
        }

        return ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall) && $call->name instanceof Identifier
            ? $call->name->toString() . '()'
            : 'a method';
    }

    private function callee(CallLike $call, Scope $scope): FunctionReflection|ExtendedMethodReflection|null
    {
        if ($call instanceof New_) {
            if (!$call->class instanceof Name || self::LATE_STATIC === $call->class->toLowerString()) {
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
            return $scope->getMethodReflection($scope->resolveTypeByName($call->class), $call->name->toString());
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
}
