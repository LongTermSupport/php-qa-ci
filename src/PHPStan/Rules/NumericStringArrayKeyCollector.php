<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
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
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
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
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Collects what ForbidNumericStringArrayKeyRule joins: each write of an array
 * element under a key that may be an all-digit string, each place an array is
 * taken as string-keyed, each closure's span, each foreach over an array whose
 * key variable its body leaves alone, and each place that reads keys no code
 * then uses where an int could misbehave.
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
 * that depth, and where foreach, a function that reads or renumbers keys, or
 * spreading reads its keys while PHPStan types them string. A function that
 * keeps its argument's keys (array_filter(), array_map() over one array, …)
 * hands the argument on.
 *
 * Keys made without an element write are judged the same way: an array
 * literal's, and those of the functions that build keys from values
 * (array_flip(), array_combine(), iterator_to_array(), …), as writes into the
 * local variable they are assigned to, or as they stand where they are
 * returned, passed, assigned to a property or have their keys read. An inline
 * `@var` asserting string keys over a value whose keys PHPStan does not know
 * is judged as it stands.
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

    /**
     * A place that reads keys, a foreach or array_keys(), whose keys then reach
     * no code where an int could misbehave: the use or report with its span is void.
     */
    public const string UNREAD = 'unread';

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
        'array_reverse'         => [0],
        'array_slice'           => [0],
        'array_splice'          => [0],
        'array_shift'           => [0],
        'array_unshift'         => [0],
    ];

    /**
     * Functions that renumber int keys unless told to keep them => the position
     * of that argument, named preserve_keys. Told to keep them, they keep keys.
     */
    private const array RENUMBERING_UNLESS_PRESERVED = ['array_reverse' => 1, 'array_slice' => 3];

    /** The name PHP gives the argument RENUMBERING_UNLESS_PRESERVED names. */
    private const string PRESERVE_KEYS = 'preserve_keys';

    /** Functions whose callback is given the key as its second parameter. */
    private const array KEY_AS_SECOND_PARAMETER = ['array_find', 'array_any', 'array_all', 'array_walk', 'array_walk_recursive'];

    /** The name PHP gives the callback of KEY_AS_SECOND_PARAMETER's functions. */
    private const string CALLBACK = 'callback';

    /** The parameter of the callback that receives the key. */
    private const int KEY_PARAMETERS = 2;

    /**
     * Functions whose result keeps the keys of some of its arguments => their
     * positions; null for every argument.
     */
    private const array KEY_PRESERVING_FUNCTIONS = [
        'array_filter'            => [0],
        'array_replace'           => null,
        'array_replace_recursive' => null,
        'array_diff'              => [0],
        'array_diff_key'          => [0],
        'array_diff_assoc'        => [0],
        'array_intersect'         => [0],
        'array_intersect_key'     => [0],
        'array_intersect_assoc'   => [0],
        'array_unique'            => [0],
        'array_change_key_case'   => [0],
    ];

    /** array_map() keeps the keys of its array only when it is given one. */
    private const string ARRAY_MAP = 'array_map';

    /** The number of arguments with which array_map() is given one array. */
    private const int ARRAY_MAP_WITH_ONE_ARRAY = 2;

    /** Functions whose result is keyed by values, or by the keys of an iterator. */
    private const array KEY_MAKING_FUNCTIONS = ['array_flip', 'array_fill_keys', 'array_combine', 'array_count_values', 'array_column', 'iterator_to_array'];

    /** The function that takes an iterator's keys, which a FilesystemIterator makes from path names unless told otherwise. */
    private const string ITERATOR_TO_ARRAY = 'iterator_to_array';

    /**
     * The iterators whose constructor takes the flags as the second argument
     * and keys by path name, which holds a `/`, unless the flags say KEY_AS_FILENAME.
     */
    private const array FILESYSTEM_ITERATORS = ['filesystemiterator', 'recursivedirectoryiterator', 'globiterator'];

    /** Functions that read a local variable by its name, or that run code which may. */
    private const array READ_BY_NAME = ['compact', 'get_defined_vars', 'extract', 'func_get_args', 'func_get_arg'];

    /** Functions that only count what they are given. */
    private const array COUNTING = ['count', 'sizeof'];

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

    /** How an array literal judged where it is handed over is named. */
    private const string LITERAL = 'The array literal';

    public function __construct(private ReflectionProvider $reflectionProvider, private FileTypeMapper $fileTypeMapper)
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
            $node instanceof Closure                                     => [$this->closure($node, $scope), ...$this->unreadKeys($scope, ...$node->stmts)],
            $node instanceof ClassMethod, $node instanceof Function_     => $this->unreadKeys($scope, ...$node->getStmts() ?? []),
            $node instanceof Expression                                  => $this->asserted($node, $scope),
            $node instanceof Assign                                      => [...$this->writes($node, $node->var, $scope), ...$this->madeWrites($node, $scope), ...$this->propertyAssignment($node, $scope), ...$this->copy($node, $scope)],
            $node instanceof AssignOp, $node instanceof AssignRef,
            $node instanceof PreInc, $node instanceof PreDec,
            $node instanceof PostInc, $node instanceof PostDec           => $this->writes($node, $node->var, $scope),
            $node instanceof Return_                                     => $this->returned($node, $scope),
            $node instanceof Foreach_                                    => $this->iterated($node, $scope),
            $node instanceof YieldFrom                                   => $this->keysRead($node, $node->expr, 'yields its keys with yield from', $scope),
            $node instanceof ArrayItem && $node->unpack                  => $this->keysRead($node, $node->value, self::SPREADS, $scope, false),
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

            $keyName = $this->keyName($key);
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

    /**
     * Each key that may be all digits made by the value assigned to a local
     * variable, recorded as a write into the variable at the key's depth.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function madeWrites(Assign $assign, Scope $scope): array
    {
        if (!$assign->var instanceof Variable || !\is_string($assign->var->name)) {
            return [];
        }

        $variable = $assign->var->name;
        $records  = [];
        foreach ($this->madeKeys($assign->expr, 0, $scope) as [$depth, $key]) {
            $records[] = $this->record(self::WRITE, $scope, $assign, $variable, [$depth], '$' . $variable . str_repeat(self::NESTED, $depth), key: $key);
        }

        return $records;
    }

    /**
     * The keys that may be all digits an expression makes without an element
     * write, each as [its depth, the key's variable name or '', what makes it]:
     * an array literal's, at every depth of nesting and spreading; those of a
     * function that keys its result by values; and those a function that keeps
     * its arguments' keys is given.
     *
     * @return list<array{int, string, string}>
     */
    private function madeKeys(Expr $expr, int $depth, Scope $scope): array
    {
        if ($expr instanceof Array_) {
            return $this->literalKeys($expr, $depth, $scope);
        }

        if (!$expr instanceof FuncCall || !$expr->name instanceof Name || $expr->isFirstClassCallable()) {
            return [];
        }

        $function = $expr->name->toString();
        $lower    = strtolower(ltrim($function, '\\'));
        if (\in_array($lower, self::KEY_MAKING_FUNCTIONS, true)) {
            return $this->madeByValues($expr, $lower, $scope) ? [[$depth, '', 'The result of ' . $function . '()']] : [];
        }

        $keys = [];
        foreach ($this->keptArguments($expr, $lower, $scope) as $kept) {
            $keys = [...$keys, ...$this->madeKeys($kept, $depth, $scope)];
        }

        return $keys;
    }

    /**
     * The keys of an array literal, of the literals spread into it and of the
     * values nested in it, that may be all digits, as madeKeys() gives them.
     *
     * @return list<array{int, string, string}>
     */
    private function literalKeys(Array_ $array, int $depth, Scope $scope): array
    {
        $keys = [];
        foreach ($array->items as $item) {
            if ($item->key instanceof Expr && $this->mayBeAllDigits($item->key, $scope)) {
                $keys[] = [$depth, $this->keyName($item->key), self::LITERAL];
            }

            if ($depth < self::MAX_DEPTH) {
                $keys = [...$keys, ...$this->madeKeys($item->value, $item->unpack ? $depth : $depth + 1, $scope)];
            }
        }

        return $keys;
    }

    /**
     * Whether a function keying its result by values gives keys that may be
     * all digits. iterator_to_array() over a FilesystemIterator, a
     * RecursiveDirectoryIterator or a GlobIterator made here takes path names,
     * which hold a `/`, unless its flags may say KEY_AS_FILENAME.
     */
    private function madeByValues(FuncCall $call, string $function, Scope $scope): bool
    {
        if (!$this->typeMayBeAllDigits($scope->getType($call)->getIterableKeyType())) {
            return false;
        }

        if (self::ITERATOR_TO_ARRAY !== $function) {
            return true;
        }

        $iterator = $call->getArgs()[0] ?? null;
        if (!$iterator instanceof Arg || !$iterator->value instanceof New_ || !$iterator->value->class instanceof Name
            || !\in_array(strtolower($scope->resolveName($iterator->value->class)), self::FILESYSTEM_ITERATORS, true)) {
            return true;
        }

        $flags = $iterator->value->getArgs()[1] ?? null;
        if (!$flags instanceof Arg) {
            return false;
        }

        $values = $scope->getType($flags->value)->getConstantScalarValues();

        return [] === $values || array_any($values, static fn (mixed $value): bool => !\is_int($value) || 0 !== ($value & \FilesystemIterator::KEY_AS_FILENAME));
    }

    /**
     * The arguments whose keys a call's result keeps: those of a function in
     * KEY_PRESERVING_FUNCTIONS, the one array array_map() is given, and the
     * array of a function told to preserve keys; with each array of a
     * literal spread into an argument list that takes every argument.
     *
     * @return list<Expr>
     */
    private function keptArguments(FuncCall $call, string $function, Scope $scope): array
    {
        $args = $call->getArgs();
        if (\array_key_exists($function, self::KEY_PRESERVING_FUNCTIONS)) {
            $positions = self::KEY_PRESERVING_FUNCTIONS[$function];
        } elseif (self::ARRAY_MAP === $function && self::ARRAY_MAP_WITH_ONE_ARRAY === \count($args)) {
            $positions = [1];
        } elseif ($this->preservesKeys($function, $scope, ...$args)) {
            $positions = [0];
        } else {
            return [];
        }

        $kept = [];
        foreach ($args as $index => $arg) {
            if (!$arg->unpack && (null === $positions || \in_array($index, $positions, true))) {
                $kept[] = $arg->value;
            } elseif ($arg->unpack && null === $positions && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $kept[] = $item->value;
                }
            }
        }

        return $kept;
    }

    /** Whether a function that renumbers int keys unless told to keep them is told to. */
    private function preservesKeys(string $function, Scope $scope, Arg ...$args): bool
    {
        if (!\array_key_exists($function, self::RENUMBERING_UNLESS_PRESERVED)) {
            return false;
        }

        $preserve = array_find($args, static fn (Arg $arg): bool => $arg->name instanceof Identifier && self::PRESERVE_KEYS === $arg->name->toString())
            ?? $args[self::RENUMBERING_UNLESS_PRESERVED[$function]] ?? null;

        return $preserve instanceof Arg && !$preserve->unpack && $scope->getType($preserve->value)->isTrue()->yes();
    }

    private function keyName(Expr $key): string
    {
        return $key instanceof Variable && \is_string($key->name) ? $key->name : '';
    }

    /** Whether PHP may store the key as an int where PHPStan types it string. */
    private function mayBeAllDigits(Expr $key, Scope $scope): bool
    {
        return $this->typeMayBeAllDigits($scope->getType($key)) && !$this->holdsANonDigit($key, $scope);
    }

    /** Whether a key of this type may be all digits, as far as the type can tell. */
    private function typeMayBeAllDigits(Type $type): bool
    {
        return $type->isString()->yes()
            && !$type->isDecimalIntegerString()->no()
            && !$type->isClassString()->yes()
            && $type->toArrayKey()->isInteger()->no();
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

    /** The key type of a type at a depth, or null where it is not known to be iterable. */
    private function keyTypeAt(Type $type, int $depth): ?Type
    {
        for ($level = 0; $level <= $depth; ++$level) {
            $type = TypeCombinator::removeNull($type);
            if (!$type->isIterable()->yes()) {
                return null;
            }

            if ($level < $depth) {
                $type = $type->getIterableValueType();
            }
        }

        return $type->getIterableKeyType();
    }

    /**
     * The local variables whose keys an expression has, each as [its name,
     * the depth of the element]: a local variable or an element of one, or
     * any of them handed through a function that keeps their keys or held in
     * a literal, where the depth goes below 0 by the levels it is nested.
     *
     * @return list<array{string, int}>
     */
    private function localArrays(Expr $expr, Scope $scope): array
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name && !$expr->isFirstClassCallable()) {
            $locals = [];
            foreach ($this->keptArguments($expr, strtolower(ltrim($expr->name->toString(), '\\')), $scope) as $kept) {
                $locals = [...$locals, ...$this->localArrays($kept, $scope)];
            }

            return $locals;
        }

        if ($expr instanceof Array_) {
            $locals = [];
            foreach ($expr->items as $item) {
                foreach ($this->localArrays($item->value, $scope) as [$variable, $depth]) {
                    $locals[] = [$variable, $item->unpack ? $depth : $depth - 1];
                }
            }

            return $locals;
        }

        $depth = 0;
        while ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr) {
            $expr = $expr->var;
            ++$depth;
        }

        return $expr instanceof Variable && \is_string($expr->name) ? [[$expr->name, $depth]] : [];
    }

    /**
     * A local array handed to a place whose declared type has string keys; the
     * keys an expression makes without an element write are judged there as
     * they stand.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function declaredUse(Node $node, Expr $value, Type $declared, string $text, Scope $scope): array
    {
        $depths = $this->stringKeyedDepths($declared);
        if ([] === $depths) {
            return [];
        }

        $records = $this->madeUse($node, $value, \sprintf('line %d %s %s', $node->getStartLine(), $text, $declared->describe(VerbosityLevel::typeOnly())), $scope, ...$depths);
        foreach ($this->localArrays($value, $scope) as [$variable, $depth]) {
            $atDepth = array_values(array_filter(array_map(static fn (int $stringKeyed): int => $stringKeyed + $depth, $depths), static fn (int $local): bool => $local >= 0));
            if ([] !== $atDepth) {
                $records[] = $this->record(self::USE, $scope, $node, $variable, $atDepth, $text . ' ' . $declared->describe(VerbosityLevel::typeOnly()));
            }
        }

        return $records;
    }

    /**
     * Each key the expression makes that may be all digits, at a depth taken as string-keyed.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function madeUse(Node $node, Expr $value, string $reason, Scope $scope, int ...$depths): array
    {
        $records = [];
        foreach ($this->madeKeys($value, 0, $scope) as [$depth, $key, $target]) {
            if (\in_array($depth, $depths, true)) {
                $records[] = $this->record(self::DECLARED, $scope, $node, $target . str_repeat(self::NESTED, $depth), [$depth], $reason, key: $key);
            }
        }

        return $records;
    }

    /**
     * An array whose keys code reads while PHPStan types them string: a local
     * one, or, unless it is spread into a literal judged where it goes, one
     * made where it stands.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysRead(Node $node, Expr $value, string $text, Scope $scope, bool $judgeMade = true): array
    {
        if (!\in_array(0, $this->stringKeyedDepths($scope->getType($value)), true)) {
            return [];
        }

        $records = [];
        foreach ($this->localArrays($value, $scope) as [$variable, $depth]) {
            if ($depth >= 0) {
                $records[] = $this->record(self::USE, $scope, $node, $variable, [$depth], $text);
            }
        }

        return $judgeMade ? [...$records, ...$this->madeUse($node, $value, \sprintf('line %d %s', $node->getStartLine(), $text), $scope, 0)] : $records;
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
     * `$copy = $array`, or `$copy` assigned what a function keeping the keys of `$array` returns.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function copy(Assign $assign, Scope $scope): array
    {
        if (!$assign->var instanceof Variable || !\is_string($assign->var->name) || !($assign->expr instanceof Variable || $assign->expr instanceof FuncCall)) {
            return [];
        }

        $records = [];
        foreach ($this->localArrays($assign->expr, $scope) as [$variable, $depth]) {
            if (0 === $depth && $variable !== $assign->var->name) {
                $records[] = $this->record(self::COPY, $scope, $assign, $variable, key: $assign->var->name);
            }
        }

        return $records;
    }

    /**
     * An inline `@var` asserting string keys that may be all digits over a
     * value whose keys at that depth PHPStan does not know to be strings.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function asserted(Expression $statement, Scope $scope): array
    {
        $assign = $statement->expr;
        $doc    = $statement->getDocComment();
        if (!$assign instanceof Assign || !$assign->var instanceof Variable || !\is_string($assign->var->name) || null === $doc) {
            return [];
        }

        $tags = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $scope->getClassReflection()?->getName(),
            $scope->getTraitReflection()?->getName(),
            $scope->getFunction()?->getName(),
            $doc->getText(),
        )->getVarTags();
        $tag = $tags[$assign->var->name] ?? $tags[0] ?? null;
        if (null === $tag) {
            return [];
        }

        $declared = $tag->getType();
        $actual   = $scope->getType($assign->expr);
        $records  = [];
        foreach ($this->stringKeyedDepths($declared) as $depth) {
            $declaredKey = $this->keyTypeAt($declared, $depth);
            if (null === $declaredKey || !$this->typeMayBeAllDigits($declaredKey) || true === $this->keyTypeAt($actual, $depth)?->isString()->yes()) {
                continue;
            }

            $records[] = $this->record(
                self::DECLARED,
                $scope,
                $assign,
                '$' . $assign->var->name . str_repeat(self::NESTED, $depth),
                [$depth],
                \sprintf('line %d asserts it %s in an inline @var over keys PHPStan does not know', $assign->getStartLine(), $declared->describe(VerbosityLevel::typeOnly())),
            );
        }

        return $records;
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
            $declared = false === $function?->isGenerator() ? $function->getReturnType() : null;
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
     * Each foreach in a function body whose key variable the body reads only
     * where an int is as good as a string: interpolated, concatenated or cast
     * to string. None where the body may read a variable by name.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function unreadKeys(Scope $scope, Node ...$statements): array
    {
        $finder = new NodeFinder();
        if ($finder->findFirst($statements, $this->readsByName(...)) instanceof Node) {
            return [];
        }

        $asString = [];
        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof InterpolatedString || $node instanceof Concat || $node instanceof StringCast || $node instanceof Foreach_) as $node) {
            $parts = match (true) {
                $node instanceof InterpolatedString => $node->parts,
                $node instanceof Concat             => [$node->left, $node->right],
                $node instanceof StringCast         => [$node->expr],
                $node instanceof Foreach_           => [$node->keyVar],
                default                             => [],
            };
            foreach ($parts as $part) {
                if ($part instanceof Variable) {
                    $asString[spl_object_id($part)] = true;
                }
            }
        }

        $records = [];
        foreach ($finder->findInstanceOf($statements, Foreach_::class) as $foreach) {
            $key = $foreach->keyVar;
            if (!$key instanceof Variable || !\is_string($key->name)) {
                continue;
            }

            $read = $finder->findFirst($statements, static fn (Node $node): bool => $node instanceof Variable && $key->name === $node->name && !isset($asString[spl_object_id($node)]));
            if (!$read instanceof Node) {
                $records[] = $this->record(self::UNREAD, $scope, $foreach);
            }
        }

        return $records;
    }

    /** Whether a node may read a local variable without naming it. */
    private function readsByName(Node $node): bool
    {
        return match (true) {
            $node instanceof Variable                          => !\is_string($node->name),
            $node instanceof Include_, $node instanceof Eval_ => true,
            $node instanceof FuncCall                          => !$node->name instanceof Name || \in_array($node->name->toLowerString(), self::READ_BY_NAME, true),
            default                                            => false,
        };
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
            $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => $isNamed($node->var) || $node->var instanceof List_ || $node->var instanceof Array_,
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
     * keys, or passed to a parameter declared string-keyed; and the keys
     * array_keys() reads where they are only counted.
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
            $records = [...$records, ...$this->keysReadBy($call->name->toString(), $scope, $call, ...$args), ...$this->counted($call, $scope)];
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
     * `count(array_keys($array))`: the keys are counted, never read.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function counted(FuncCall $call, Scope $scope): array
    {
        $counted = $call->getArgs()[0] ?? null;

        return $call->name instanceof Name && \in_array($call->name->toLowerString(), self::COUNTING, true)
            && $counted instanceof Arg && $counted->value instanceof FuncCall && $counted->value->name instanceof Name
            && 'array_keys' === $counted->value->name->toLowerString()
            ? [$this->record(self::UNREAD, $scope, $counted->value)]
            : [];
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysReadBy(string $function, Scope $scope, FuncCall $call, Arg ...$args): array
    {
        $lower = strtolower(ltrim($function, '\\'));
        if ($this->preservesKeys($lower, $scope, ...$args) || $this->callbackTakesNoKey($lower, ...$args)) {
            return [];
        }

        if (\array_key_exists($lower, self::KEY_READING_FUNCTIONS)) {
            $positions = self::KEY_READING_FUNCTIONS[$lower];
        } elseif (self::ARRAY_FILTER === $lower && \count($args) >= self::ARRAY_FILTER_WITH_MODE) {
            $positions = [0];
        } else {
            return [];
        }

        $text    = 'reads its keys with ' . $function . '()';
        $records = [];
        foreach ($args as $index => $arg) {
            $named = $arg->name instanceof Identifier && \in_array($arg->name->toString(), self::KEY_READING_PARAMETERS, true);
            if (!$arg->unpack && (null === $positions || $named || (!$arg->name instanceof Identifier && \in_array($index, $positions, true)))) {
                $records = [...$records, ...$this->keysRead($call, $arg->value, $text, $scope)];
            } elseif ($arg->unpack && null === $positions && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $records = [...$records, ...$this->keysRead($call, $item->value, $text, $scope)];
                }
            }
        }

        return $records;
    }

    /**
     * Whether a function that gives its callback the key as the second
     * parameter is given a closure that takes fewer, and does not ask for its
     * arguments another way.
     */
    private function callbackTakesNoKey(string $function, Arg ...$args): bool
    {
        if (!\in_array($function, self::KEY_AS_SECOND_PARAMETER, true)) {
            return false;
        }

        $callback = array_find($args, static fn (Arg $arg): bool => $arg->name instanceof Identifier && self::CALLBACK === $arg->name->toString()) ?? $args[1] ?? null;
        if (!$callback instanceof Arg || !($callback->value instanceof Closure || $callback->value instanceof ArrowFunction)) {
            return false;
        }

        $closure = $callback->value;

        return \count($closure->params) < self::KEY_PARAMETERS
            && !array_any($closure->params, static fn (Param $param): bool => $param->variadic)
            && !new NodeFinder()->findFirst($closure->getStmts(), $this->readsByName(...)) instanceof Node;
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
