<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use ArrayIterator;
use ArrayObject;
use FilesystemIterator;
use Generator;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\AssignOp\Plus as PlusAssign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
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
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\VerbosityLevel;

/**
 * Collects what ForbidNumericStringArrayKeyRule joins: each write of an array
 * element under a key that may be an all-digit string, each place an array is
 * taken as string-keyed, how arrays are handed from one variable to another,
 * each closure's span, each foreach over an array whose key variable its body
 * leaves alone, each place that reads keys no code then uses where an int
 * could misbehave, and what PHPStan knew of each variable where it was written.
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
 * name; the variable is taken as string-keyed where it is returned, yielded,
 * passed as an argument, handed back through a by-reference parameter or
 * assigned to a property whose declared type has string keys at that depth,
 * and where foreach, a function that reads or renumbers keys, or spreading
 * reads its keys while PHPStan types them string. A variable is followed into
 * another assigned it, an element of it, its foreach value or a reference to
 * it, at the depth it lands; through an operator or a branch (`+`, `??`, `?:`,
 * a match arm); and through a function or class that keeps its argument's
 * keys (array_filter(), ArrayIterator, a template that hands the keys back).
 *
 * Keys made without an element write are judged the same way: an array
 * literal's, and those of the functions that build keys from values
 * (array_flip(), array_combine(), iterator_to_array(), …), as writes into the
 * variable they are assigned to, or as they stand where they are returned,
 * passed, assigned to a property or have their keys read. An inline `@var`
 * asserting string keys over a value whose keys PHPStan does not know is
 * judged against what the statement binds, or else against every write of the
 * variable that may reach it.
 *
 * @internal
 *
 * @phpstan-type NumericStringArrayKeyRecord array{kind: string, functionKey: string, variable: string, key: string, depths: list<int>, line: int, start: int, end: int, text: string, uses: list<string>, unless: string}
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
     * An arrow function: it takes every variable of the enclosing body by
     * value, so a call in it that takes one by reference writes the arrow
     * function's own copy, never the enclosing body's variable.
     */
    public const string ARROW_FUNCTION = 'arrowFunction';

    /**
     * A foreach over an array whose key variable nothing in the loop writes:
     * in the loop the variable holds a key PHP made, and a string PHP leaves
     * as a key is never all digits.
     */
    public const string KEY_BINDING = 'keyBinding';

    /**
     * One variable holding another's keys at an offset of depth: the record's
     * variable is the source, its key the copy, and its depth the offset, so
     * that the copy's depth d is the source's depth d plus the offset.
     */
    public const string COPY = 'copy';

    /**
     * A place that reads keys, a foreach or array_keys(), whose keys then reach
     * no code where an int could misbehave: the use or report with its span is void.
     */
    public const string UNREAD = 'unread';

    /**
     * A write of a local variable, at the position PHPStan records it, with
     * the depths whose keys PHPStan typed string there; its text says when it
     * holds a filesystem iterator keyed by path name.
     */
    public const string BINDING = 'binding';

    /**
     * A function-like body, as NumericStringArrayKeyWritesCollector records
     * it: in its uses, each write of a local variable as name@position, and
     * name@-1 for a variable written where PHPStan cannot follow it; its key
     * is '*' where any variable may be written unseen.
     */
    public const string WRITES = 'writes';

    /**
     * An inline `@var` asserting string keys on a variable the statement does
     * not bind: reported unless every write that may reach it was typed so.
     */
    public const string HELD = 'held';

    /**
     * An inline `@var` asserting string keys over keys PHPStan did not know
     * on a variable the statement binds: reported as it stands, whatever the
     * statement's own keys are.
     */
    public const string ASSERTED = 'asserted';

    /** A loop: a write later in it may reach a statement earlier in it. */
    public const string LOOP = 'loop';

    /** A variable named `global`, which every function that so names it shares. */
    public const string GLOBAL = 'global';

    /** A use of an iterator's variable that may change how it keys, or hand it on. */
    public const string ESCAPE = 'escape';

    /**
     * A statement in a list that writes the whole of a variable on every
     * path through it that goes on to the next statement, with in its depths
     * where the list ends: no write before it reaches a statement after it in
     * the list, or nested in one after it.
     */
    public const string KILL = 'kill';

    /**
     * A local variable, or an element of one, passed to a parameter that may
     * take it by reference: a write at the argument's position, with the
     * depths whose keys PHPStan types string once the call returns; none
     * where the callee or its parameter cannot be resolved.
     */
    public const string BY_REFERENCE = 'byReference';

    /** What a filesystem iterator keyed by path name is called in a BINDING's text. */
    public const string PATH_NAMES = 'pathNames';

    /** How a write PHPStan cannot follow is positioned in a WRITES record. */
    public const int UNTRACKED = -1;

    /**
     * How a WRITES record marks a variable PHPStan stops following only
     * because it is passed by reference: its BY_REFERENCE records are its
     * other writes.
     */
    public const int PASSED_BY_REFERENCE = -2;

    /** The key of a WRITES record whose body may write any variable unseen. */
    public const string ANY = '*';

    /** The prefix of a variable reached through $GLOBALS rather than its name. */
    public const string GLOBALS = 'GLOBALS:';

    /** How deep a declared type is searched for string keys. */
    private const int MAX_DEPTH = 8;

    /** The only characters of a decimal integer string. */
    private const string DECIMAL_INTEGER_CHARACTERS = '-0123456789';

    /** Names whatever class the call is made on, which a subclass may be. */
    private const string LATE_STATIC = 'static';

    /** The variable that is the object, never an array. */
    private const string THIS = 'this';

    /** The namespace whose functions behave as PHP's own of the same name, throwing where PHP returns false. */
    private const string SAFE = 'safe\\';

    /**
     * Functions that hand an array's keys to code, or renumber its int keys,
     * => the positions of the arguments whose keys they read; null for every
     * argument.
     */
    private const array KEY_READING_FUNCTIONS = [
        'array_keys'              => [0],
        'array_key_first'         => [0],
        'array_key_last'          => [0],
        'key'                     => [0],
        'array_flip'              => [0],
        'array_search'            => [1],
        'array_find_key'          => [0],
        'array_find'              => [0],
        'array_any'               => [0],
        'array_all'               => [0],
        'array_walk'              => [0],
        'array_walk_recursive'    => [0],
        'uksort'                  => [0],
        'array_rand'              => [0],
        'array_is_list'           => [0],
        'json_encode'             => [0],
        'http_build_query'        => [0],
        'array_diff_ukey'         => null,
        'array_diff_uassoc'       => null,
        'array_udiff_uassoc'      => null,
        'array_intersect_ukey'    => null,
        'array_intersect_uassoc'  => null,
        'array_uintersect_uassoc' => null,
        'array_merge'             => null,
        'array_merge_recursive'   => null,
        'array_multisort'         => null,
        'array_pad'               => [0],
        'array_reverse'           => [0],
        'array_slice'             => [0],
        'array_splice'            => [0],
        'array_shift'             => [0],
        'array_unshift'           => [0],
    ];

    /**
     * Functions that renumber int keys unless told to keep them => the position
     * of that argument, named preserve_keys. Told to keep them, they keep keys:
     * array_chunk() one level down.
     */
    private const array RENUMBERING_UNLESS_PRESERVED = ['array_reverse' => 1, 'array_slice' => 3, 'array_chunk' => 2];

    /** Functions that sort the array they take by reference and keep its keys. */
    private const array KEY_KEEPING_SORTS = ['ksort', 'krsort', 'asort', 'arsort', 'uasort', 'uksort', 'natsort', 'natcasesort'];

    /** Functions that reorder the array they take by reference and renumber it as a list. */
    private const array LIST_MAKING_SORTS = ['sort', 'rsort', 'usort', 'shuffle'];

    /** Functions that add the values they are given to the array they take by reference, under int keys. */
    private const array APPENDING_FUNCTIONS = ['array_push', 'array_unshift'];

    /** The number of arguments with which array_push(), array_unshift() and array_splice() change the array. */
    private const int WITH_VALUES = 2;

    /** The function that takes the last element off the array it takes by reference. */
    private const string ARRAY_POP = 'array_pop';

    /** The function that takes the first element off the array it takes by reference, renumbering int keys. */
    private const string ARRAY_SHIFT = 'array_shift';

    /** The function that replaces part of the array it takes by reference. */
    private const string ARRAY_SPLICE = 'array_splice';

    /** The name PHP gives the array its array functions change in place. */
    private const string ARRAY_PARAMETER = 'array';

    /** The function that keeps keys one level down, in each chunk. */
    private const string ARRAY_CHUNK = 'array_chunk';

    /** The name PHP gives the argument RENUMBERING_UNLESS_PRESERVED names. */
    private const string PRESERVE_KEYS = 'preserve_keys';

    /** Functions whose callback is given the key as its second parameter. */
    private const array KEY_AS_SECOND_PARAMETER = ['array_find', 'array_any', 'array_all', 'array_walk', 'array_walk_recursive'];

    /** The name PHP gives the callback of KEY_AS_SECOND_PARAMETER's functions. */
    private const string CALLBACK = 'callback';

    /** The parameter of the callback that receives the key. */
    private const int KEY_PARAMETERS = 2;

    /** json_encode() writes every array as an object when its flags hold this. */
    private const string JSON_ENCODE = 'json_encode';

    /** http_build_query() writes an int key differently only with a numeric prefix, its second argument. */
    private const string HTTP_BUILD_QUERY = 'http_build_query';

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
        'array_diff_ukey'         => [0],
        'array_diff_uassoc'       => [0],
        'array_udiff'             => [0],
        'array_udiff_assoc'       => [0],
        'array_udiff_uassoc'      => [0],
        'array_intersect'         => [0],
        'array_intersect_key'     => [0],
        'array_intersect_assoc'   => [0],
        'array_intersect_ukey'    => [0],
        'array_intersect_uassoc'  => [0],
        'array_uintersect'        => [0],
        'array_uintersect_assoc'  => [0],
        'array_uintersect_uassoc' => [0],
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

    /** Classes that hold the array they are made from, keys and all, and hand it back from getArrayCopy(). */
    private const array KEY_KEEPING_CLASSES = [ArrayObject::class, ArrayIterator::class];

    /** The method of KEY_KEEPING_CLASSES that hands the array back. */
    private const string GET_ARRAY_COPY = 'getarraycopy';

    /** Functions that read a local variable by its name, or that run code which may. */
    private const array READ_BY_NAME = ['compact', 'get_defined_vars', 'extract', 'func_get_args', 'func_get_arg'];

    /** Functions that only count what they are given. */
    private const array COUNTING = ['count', 'sizeof'];

    /** The function that hands an array's keys on as a list. */
    private const string ARRAY_KEYS = 'array_keys';

    /** The function that makes an int a string. */
    private const string STRVAL = 'strval';

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

    /** How a declared type is introduced in a reason. */
    private const string DECLARED_AS = ', declared';

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
            $node instanceof Closure                                             => [$this->closure($node, $scope), ...$this->unreadKeys($scope, ...$node->stmts), ...$this->escapes($scope, ...$node->stmts), ...$this->parameters($node, $scope), ...$this->kills($scope, ...$node->stmts)],
            $node instanceof ClassMethod, $node instanceof Function_             => [...$this->unreadKeys($scope, ...$node->getStmts() ?? []), ...$this->escapes($scope, ...$node->getStmts() ?? []), ...$this->parameters($node, $scope), ...$this->kills($scope, ...$node->getStmts() ?? [])],
            $node instanceof ArrowFunction                                       => [$this->record(self::ARROW_FUNCTION, $scope, $node), ...$this->parameters($node, $scope)],
            $node instanceof Assign                                              => [...$this->writes($node, $node->var, $scope), ...$this->assigned($node, $node->var, $node->expr, $scope), ...$this->bindings($node, $scope)],
            $node instanceof AssignRef                                           => [...$this->writes($node, $node->var, $scope), ...$this->assigned($node, $node->var, $node->expr, $scope, true)],
            $node instanceof CoalesceAssign, $node instanceof PlusAssign         => [...$this->writes($node, $node->var, $scope), ...$this->assigned($node, $node->var, $node->expr, $scope), ...$this->bindings($node, $scope)],
            $node instanceof AssignOp, $node instanceof PreInc, $node instanceof PreDec,
            $node instanceof PostInc, $node instanceof PostDec                   => [...$this->writes($node, $node->var, $scope), ...$this->bindings($node, $scope)],
            $node instanceof Return_                                             => [...$this->returned($node, $scope), ...$this->asserted($node, $scope)],
            $node instanceof Foreach_                                            => [...$this->iterated($node, $scope), ...$this->asserted($node, $scope), $this->record(self::LOOP, $scope, $node)],
            $node instanceof For_, $node instanceof While_, $node instanceof Do_ => [...$this->asserted($node, $scope), $this->record(self::LOOP, $scope, $node)],
            $node instanceof Global_                                             => [...$this->globals($node, $scope), ...$this->asserted($node, $scope)],
            $node instanceof Yield_                                              => $this->yielded($node, $scope),
            $node instanceof YieldFrom                                           => $this->keysRead($node, $node->expr, 'yields its keys with yield from', $scope),
            $node instanceof ArrayItem && $node->unpack                          => $this->keysRead($node, $node->value, self::SPREADS, $scope, false),
            $node instanceof CallLike  && !$node->isFirstClassCallable()         => [...$this->passed($node, $scope), ...$this->byReference($node, $scope)],
            $node instanceof Stmt                                                => $this->isStatementInCode($node) ? $this->asserted($node, $scope) : [],
            default                                                              => [],
        };

        return [] === $records ? null : $records;
    }

    /**
     * @param list<int>    $depths
     * @param list<string> $uses
     *
     * @return NumericStringArrayKeyRecord
     */
    private function record(string $kind, Scope $scope, Node $node, string $variable = '', array $depths = [], string $text = '', array $uses = [], string $key = '', string $unless = ''): array
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
            'unless'      => $unless,
        ];
    }

    /** Whether PHPStan applies an inline `@var` on the statement, as it does on any in code. */
    private function isStatementInCode(Stmt $statement): bool
    {
        return !$statement instanceof ClassLike && !$statement instanceof Property && !$statement instanceof ClassConst && !$statement instanceof Const_
                                                && !$statement instanceof Namespace_ && !$statement instanceof Use_ && !$statement instanceof GroupUse && !$statement instanceof Declare_;
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
     * The local variable an expression is, or an element of: its name, the
     * keys from it outward, and how it is written. `$GLOBALS['name']` is the
     * global variable of that name.
     *
     * @return array{string, list<Expr|null>, string}|null
     */
    private function target(Expr $expr): ?array
    {
        $keys = [];
        while ($expr instanceof ArrayDimFetch) {
            array_unshift($keys, $expr->dim);
            $expr = $expr->var;
        }

        if (!$expr instanceof Variable || !\is_string($expr->name) || self::THIS === $expr->name) {
            return null;
        }

        $global = $keys[0] ?? null;
        if ('GLOBALS' === $expr->name && $global instanceof String_) {
            array_shift($keys);

            return [self::GLOBALS . $global->value, $keys, \sprintf("\$GLOBALS['%s']", $global->value)];
        }

        return [$expr->name, $keys, '$' . $expr->name];
    }

    /**
     * Each key of the written element that may be all digits: recorded against
     * a local variable, or against a property declared string-keyed at its depth.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function writes(Expr $write, Expr $target, Scope $scope): array
    {
        $local = $this->target($target);
        $keys  = [];
        $root  = $target;
        while ($root instanceof ArrayDimFetch) {
            array_unshift($keys, $root->dim);
            $root = $root->var;
        }

        $records = [];
        foreach (null === $local ? $keys : $local[1] as $depth => $key) {
            if (!$key instanceof Expr || !$this->mayBeAllDigits($key, $scope)) {
                continue;
            }

            $keyName = $this->keyName($key);
            if (null !== $local) {
                $records[] = $this->record(self::WRITE, $scope, $write, $local[0], [$depth], $local[2] . str_repeat(self::NESTED, $depth), key: $keyName);

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
     * A value assigned (or added with `+=`, `??=`, or made a reference with
     * `=&`) to a variable, an element of one or a property: the keys it makes
     * are writes into the variable at their depth, the variables it holds are
     * handed on at theirs, and a property, or an element of one, takes it as
     * its declared type.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function assigned(Expr $node, Expr $target, Expr $value, Scope $scope, bool $byReference = false): array
    {
        $records = [];
        $local   = $this->target($target);
        if (null !== $local) {
            [$variable, $keys, $text] = $local;
            $levels                   = \count($keys);
            foreach ($this->madeKeys($value, $levels, $scope) as [$depth, $key, , $unless]) {
                $records[] = $this->record(self::WRITE, $scope, $node, $variable, [$depth], $text . str_repeat(self::NESTED, $depth), key: $key, unless: $unless);
            }

            foreach ($this->localArrays($value, $scope) as [$source, $depth]) {
                $offset = $depth - $levels;
                if ($source !== $variable || 0 !== $offset) {
                    $records[] = $this->record(self::COPY, $scope, $node, $source, [$offset], key: $variable);
                    if ($byReference) {
                        $records[] = $this->record(self::COPY, $scope, $node, $variable, [-$offset], key: $source);
                    }
                }
            }

            $referenced = $byReference ? $this->declaredTargetType($value, $scope) : null;
            $depths     = null === $referenced ? [] : $this->stringKeyedDepths($referenced[0]);
            if (null !== $referenced && [] !== $depths) {
                $records[] = $this->record(
                    self::USE,
                    $scope,
                    $node,
                    $variable,
                    array_map(static fn (int $depth): int => $depth + $levels, $depths),
                    'makes it a reference to ' . $referenced[1] . self::DECLARED_AS . ' ' . $referenced[0]->describe(VerbosityLevel::typeOnly()),
                );
            }
        } elseif ($target instanceof List_ || $target instanceof Array_) {
            foreach ($this->destructured($target, new MixedType(), $scope) as [$element, $below]) {
                foreach ($this->localArrays($value, $scope) as [$source, $depth]) {
                    $records[] = $this->record(self::COPY, $scope, $node, $source, [$depth + $below], key: $element);
                }
            }
        }

        $declared = $this->declaredTargetType($target, $scope);
        if (null !== $declared) {
            return [...$records, ...$this->declaredUse($node, $value, $declared[0], 'assigns it to ' . $declared[1] . self::DECLARED_AS, $scope)];
        }

        return $records;
    }

    /**
     * What PHPStan knew of each local variable an assignment writes, at the
     * position it records the write: the depths whose keys it typed string.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function bindings(Expr $node, Scope $scope): array
    {
        if (!$node instanceof Assign && !$node instanceof AssignOp && !$node instanceof PreInc && !$node instanceof PreDec && !$node instanceof PostInc && !$node instanceof PostDec) {
            return [];
        }

        $target = $node->var;
        if ($target instanceof List_ || $target instanceof Array_) {
            return $node instanceof Assign ? $this->destructuredBindings($target, $scope->getType($node->expr), $scope) : [];
        }

        if ($target instanceof Variable && \is_string($target->name)) {
            $type = $node instanceof Assign ? $scope->getType($node->expr) : $scope->getType($node);
            $text = $node instanceof Assign && $node->expr instanceof New_ && $this->keysByPathName($node->expr, $scope) ? self::PATH_NAMES : '';

            return [$this->record(self::BINDING, $scope, $target, $target->name, $this->knownDepths($type), $text)];
        }

        $local = $this->target($target);
        if (null === $local || str_starts_with($local[0], self::GLOBALS)) {
            return [];
        }

        $levels = \count($local[1]);
        $value  = $node instanceof Assign ? $this->knownDepths($scope->getType($node->expr)) : [];
        $known  = [];
        foreach ($local[1] as $depth => $key) {
            if ($key instanceof Expr && $scope->getType($key)->isString()->yes()) {
                $known[] = $depth;
            }
        }

        foreach ($value as $depth) {
            $known[] = $depth + $levels;
        }

        return [$this->record(self::BINDING, $scope, $target, $local[0], $known)];
    }

    /**
     * The variables a destructuring writes, each with what PHPStan knew of the
     * element it takes.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function destructuredBindings(List_|Array_ $list, Type $type, Scope $scope): array
    {
        $records = [];
        foreach ($this->destructured($list, $type, $scope) as [$name, , $element, $variable]) {
            $records[] = $this->record(self::BINDING, $scope, $variable, $name, $this->knownDepths($element));
        }

        return $records;
    }

    /**
     * The variables a destructuring writes, each as [its name, how many
     * levels below the destructured value it lies, the type of the element it
     * takes, the variable as written].
     *
     * @return list<array{string, int, Type, Variable}>
     */
    private function destructured(List_|Array_ $list, Type $type, Scope $scope, int $depth = 1): array
    {
        $found = [];
        $index = 0;
        foreach ($list->items as $item) {
            if (!$item instanceof ArrayItem) {
                ++$index;

                continue;
            }

            $offset  = $item->key instanceof Expr ? $scope->getType($item->key) : new ConstantIntegerType($index++);
            $element = $type->getOffsetValueType($offset);
            if ($item->value instanceof Variable && \is_string($item->value->name)) {
                $found[] = [$item->value->name, $depth, $element, $item->value];
            } elseif (($item->value instanceof List_ || $item->value instanceof Array_) && $depth < self::MAX_DEPTH) {
                $found = [...$found, ...$this->destructured($item->value, $element, $scope, $depth + 1)];
            }
        }

        return $found;
    }

    /**
     * The depths at which PHPStan knows a value's keys to be strings: those of
     * an array part that holds none are known too.
     *
     * @return list<int>
     */
    private function knownDepths(Type $type): array
    {
        $known = [];
        $empty = false;
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $type = TypeCombinator::intersect($type, new IterableType(new MixedType(), new MixedType()));
            if ($empty || $type->isIterableAtLeastOnce()->no()) {
                $empty   = true;
                $known[] = $depth;

                continue;
            }

            if (!$type->isIterable()->yes()) {
                break;
            }

            if ($type->getIterableKeyType()->isString()->yes()) {
                $known[] = $depth;
            }

            $type = $type->getIterableValueType();
        }

        return $known;
    }

    /**
     * The keys that may be all digits an expression makes without an element
     * write, each as [its depth, the key's variable name or '', what makes it,
     * the variable holding an iterator whose keys they are or '']: an array
     * literal's, at every depth of nesting and spreading; those of a function
     * that keys its result by values; and those a function or class that keeps
     * its arguments' keys is given, through any operator or branch.
     *
     * @return list<array{int, string, string, string}>
     */
    private function madeKeys(Expr $expr, int $depth, Scope $scope): array
    {
        $branches = $this->branches($expr);
        if (null !== $branches) {
            $keys = [];
            foreach ($branches as $branch) {
                $keys = [...$keys, ...$this->madeKeys($branch, $depth, $scope)];
            }

            return $keys;
        }

        if ($expr instanceof Array_) {
            return $this->literalKeys($expr, $depth, $scope);
        }

        if (!$expr instanceof CallLike || $expr->isFirstClassCallable()) {
            return [];
        }

        $function = $this->functionName($expr);
        if (null !== $function && \in_array($function, self::KEY_MAKING_FUNCTIONS, true) && $expr instanceof FuncCall && $expr->name instanceof Name) {
            $unless = $this->madeByValues($expr, $function, $scope);

            return null === $unless ? [] : [[$depth, '', 'The result of ' . $expr->name->toString() . '()', $unless]];
        }

        $keys = [];
        foreach ($this->keptArguments($expr, $scope) as [$kept, $shift]) {
            $keys = [...$keys, ...$this->madeKeys($kept, $depth + $shift, $scope)];
        }

        return $keys;
    }

    /**
     * The expressions whose value an expression may be: both branches of a
     * ternary, each arm of a match, both sides of `??` and of `+`, the value
     * assigned, or what is cast or silenced; null for any other expression.
     *
     * @return list<Expr>|null
     */
    private function branches(Expr $expr): ?array
    {
        return match (true) {
            $expr instanceof Ternary                                         => [$expr->if ?? $expr->cond, $expr->else],
            $expr instanceof Match_                                          => array_values(array_map(static fn (MatchArm $arm): Expr => $arm->body, $expr->arms)),
            $expr instanceof Coalesce, $expr instanceof Plus                 => [$expr->left, $expr->right],
            $expr instanceof CoalesceAssign, $expr instanceof PlusAssign     => [$expr->var, $expr->expr],
            $expr instanceof Assign, $expr instanceof AssignRef,
            $expr instanceof ArrayCast, $expr instanceof ErrorSuppress       => [$expr->expr],
            default                                                          => null,
        };
    }

    /**
     * The keys of an array literal, of the literals spread into it and of the
     * values nested in it, that may be all digits, as madeKeys() gives them.
     *
     * @return list<array{int, string, string, string}>
     */
    private function literalKeys(Array_ $array, int $depth, Scope $scope): array
    {
        $keys = [];
        foreach ($array->items as $item) {
            if ($item->key instanceof Expr && $this->mayBeAllDigits($item->key, $scope)) {
                $keys[] = [$depth, $this->keyName($item->key), self::LITERAL, ''];
            }

            if ($depth < self::MAX_DEPTH) {
                $keys = [...$keys, ...$this->madeKeys($item->value, $item->unpack ? $depth : $depth + 1, $scope)];
            }
        }

        return $keys;
    }

    /**
     * Whether a function keying its result by values gives keys that may be
     * all digits: null where it does not, '' where it may, and the name of
     * the variable holding the iterator where iterator_to_array() is given a
     * variable, whose keys are path names when every write of it is a
     * filesystem iterator keyed so. iterator_to_array() over a
     * FilesystemIterator, a RecursiveDirectoryIterator or a GlobIterator made
     * here takes path names, which hold a `/`, unless its flags may say
     * KEY_AS_FILENAME.
     */
    private function madeByValues(FuncCall $call, string $function, Scope $scope): ?string
    {
        if (!$this->typeMayBeAllDigits($scope->getType($call)->getIterableKeyType())) {
            return null;
        }

        $iterator = $call->getArgs()[0] ?? null;
        if (self::ITERATOR_TO_ARRAY !== $function || !$iterator instanceof Arg || $iterator->unpack) {
            return '';
        }

        if ($iterator->value instanceof Variable && \is_string($iterator->value->name)) {
            return $iterator->value->name;
        }

        return $iterator->value instanceof New_ && $this->keysByPathName($iterator->value, $scope) ? null : '';
    }

    /** Whether a filesystem iterator made here keys by path name: its flags cannot say KEY_AS_FILENAME. */
    private function keysByPathName(New_ $iterator, Scope $scope): bool
    {
        if (!$iterator->class instanceof Name || !\in_array(strtolower($scope->resolveName($iterator->class)), self::FILESYSTEM_ITERATORS, true)) {
            return false;
        }

        $flags = $iterator->getArgs()[1] ?? null;
        if (!$flags instanceof Arg) {
            return true;
        }

        $values = $scope->getType($flags->value)->getConstantScalarValues();

        return [] !== $values && !array_any($values, static fn (mixed $value): bool => !\is_int($value) || 0 !== ($value & FilesystemIterator::KEY_AS_FILENAME));
    }

    /**
     * The function a call names, lower-cased, without a leading backslash or
     * the Safe\ namespace, whose functions are PHP's that throw; null for any
     * other call.
     */
    private function functionName(CallLike $call): ?string
    {
        if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));

        return str_starts_with($name, self::SAFE) ? substr($name, \strlen(self::SAFE)) : $name;
    }

    /**
     * The arguments whose keys a call's result keeps, each with the depth in
     * the result its own keys land at: those of a function in
     * KEY_PRESERVING_FUNCTIONS, the one array array_map() is given, the array
     * of a function told to preserve keys, the array an ArrayObject or
     * ArrayIterator is made from or hands back, and an argument whose keys
     * are a template of the callee that its return type has as keys; with
     * each array of a literal spread into an argument list that takes every
     * argument.
     *
     * @return list<array{Expr, int}>
     */
    private function keptArguments(CallLike $call, Scope $scope): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $args     = $call->getArgs();
        $function = $this->functionName($call);
        if ($call instanceof New_) {
            return $this->isKeyKeeping($call, $scope) && isset($args[0]) && !$args[0]->unpack ? [[$args[0]->value, 0]] : [];
        }

        if (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->name instanceof Identifier && self::GET_ARRAY_COPY === $call->name->toLowerString()
                                                                                 && $this->holdsKeyKeeping($scope->getType($call->var))) {
            return [[$call->var, 0]];
        }

        if (null !== $function && \array_key_exists($function, self::KEY_PRESERVING_FUNCTIONS)) {
            return array_map(static fn (Expr $kept): array => [$kept, 0], $this->argumentsAt(self::KEY_PRESERVING_FUNCTIONS[$function], ...$args));
        }

        if (self::ARRAY_MAP === $function) {
            return self::ARRAY_MAP_WITH_ONE_ARRAY === \count($args) && !$args[1]->unpack ? [[$args[1]->value, 0]] : [];
        }

        if (null !== $function && $this->preservesKeys($function, $scope, ...$args)) {
            $shift = self::ARRAY_CHUNK === $function ? 1 : 0;

            return array_map(static fn (Expr $kept): array => [$kept, $shift], $this->argumentsAt([0], ...$args));
        }

        return null === $function || !$this->isListed($function) ? $this->genericFlows($call, $scope)[0] : [];
    }

    /** Whether a function is one this collector knows by name, so that its signature is not read for what it does with keys. */
    private function isListed(string $function): bool
    {
        return \array_key_exists($function, self::KEY_READING_FUNCTIONS) || \array_key_exists($function, self::KEY_PRESERVING_FUNCTIONS) || \array_key_exists($function, self::RENUMBERING_UNLESS_PRESERVED)
                                                                         || \in_array($function, [...self::KEY_MAKING_FUNCTIONS, ...self::COUNTING, self::ARRAY_MAP, self::ARRAY_FILTER], true);
    }

    /** Whether `new` makes an ArrayObject or an ArrayIterator, or a subclass, which holds the array it is given. */
    private function isKeyKeeping(New_ $new, Scope $scope): bool
    {
        if (!$new->class instanceof Name) {
            return false;
        }

        $class = $scope->resolveName($new->class);

        return $this->reflectionProvider->hasClass($class)
            && array_any(self::KEY_KEEPING_CLASSES, fn (string $keeping): bool => $this->reflectionProvider->getClass($class)->is($keeping));
    }

    private function holdsKeyKeeping(Type $type): bool
    {
        return array_any(self::KEY_KEEPING_CLASSES, static fn (string $keeping): bool => new ObjectType($keeping)->isSuperTypeOf($type)->yes());
    }

    /**
     * The arguments at positions, or named as the array PHP's functions read;
     * every argument, and each item of a literal spread into them, for null.
     *
     * @param list<int>|null $positions
     *
     * @return list<Expr>
     */
    private function argumentsAt(?array $positions, Arg ...$args): array
    {
        $found = [];
        foreach ($args as $index => $arg) {
            $named = $arg->name instanceof Identifier && \in_array($arg->name->toString(), self::KEY_READING_PARAMETERS, true);
            if (!$arg->unpack && (null === $positions || $named || (!$arg->name instanceof Identifier && \in_array($index, $positions, true)))) {
                $found[] = $arg->value;
            } elseif ($arg->unpack && null === $positions && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $found[] = $item->value;
                }
            }
        }

        return $found;
    }

    /**
     * What a call to a function or method generic in its arguments' keys does
     * with them, read from its signature, as [the arguments its result keeps,
     * each with the depth in the result their own keys land at; the arguments
     * whose keys it hands elsewhere, each with the depth of those keys; for
     * each argument position, the depths whose keys its parameter takes as a
     * template of the callee, which the parameter's type declares nothing about].
     *
     * @return array{list<array{Expr, int}>, list<array{Expr, int}>, array<array-key, list<int>>}
     */
    private function genericFlows(CallLike $call, Scope $scope): array
    {
        $callee = $this->callee($call, $scope);
        if (null === $callee || $call->isFirstClassCallable()) {
            return [[], [], []];
        }

        $kept     = [];
        $read     = [];
        $excluded = [];
        $args     = $call->getArgs();
        $resolved = ParametersAcceptorSelector::selectFromArgs($scope, $args, $callee->getVariants())->getParameters();
        foreach ($args as $index => $arg) {
            $parameter = $arg->unpack ? null : $this->parameterFor($arg, $index, ...$resolved);
            $declared  = $parameter instanceof ParameterReflection ? $this->unresolved($parameter->getName(), ...$callee->getVariants()) : null;
            if (null === $declared) {
                continue;
            }

            [$variant, $type] = $declared;
            foreach ($this->keyTemplates($type) as [$depth, $template, $whole, $undeclared]) {
                $excluded[$index] = [...$excluded[$index] ?? [], ...$undeclared];
                $returned         = $this->templateKeyDepths($variant->getReturnType(), $template, $whole);
                foreach ($returned as $landing) {
                    $kept[] = [$arg->value, $landing - $depth];
                }

                if ([] === $returned && $this->templateElsewhere($template, $variant, $parameter->getName())) {
                    $read[] = [$arg->value, $depth];
                }
            }
        }

        return [$kept, $read, $excluded];
    }

    /**
     * A parameter as the callee declares it, before its templates are
     * resolved, with the variant declaring it.
     *
     * @return array{ParametersAcceptor, Type}|null
     */
    private function unresolved(string $name, ParametersAcceptor ...$variants): ?array
    {
        foreach ($variants as $variant) {
            foreach ($variant->getParameters() as $parameter) {
                if ($parameter->getName() === $name) {
                    return [$variant, $parameter->getType()];
                }
            }
        }

        return null;
    }

    /**
     * The templates of the callee itself in a parameter's type: a key type
     * that is one, or a value that is one (`@param TArray $array`), each as
     * [its depth, its name, whether it is the value, the depths whose keys it
     * declares nothing about]. A value that is a template declares only what
     * its bound declares, at every depth below it.
     *
     * @return list<array{int, string, bool, list<int>}>
     */
    private function keyTemplates(Type $type): array
    {
        $found = [];
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $type  = TypeCombinator::removeNull($type);
            $whole = $this->templateName($type);
            if (null !== $whole) {
                $bound   = array_map(static fn (int $below): int => $below + $depth, $this->stringKeyedDepths($type));
                $found[] = [$depth, $whole, true, array_values(array_diff(range($depth, self::MAX_DEPTH - 1), $bound))];

                break;
            }

            if (!$type->isIterable()->yes()) {
                break;
            }

            $name = $this->templateName($type->getIterableKeyType());
            if (null !== $name) {
                $found[] = [$depth, $name, false, [$depth]];
            }

            $type = $type->getIterableValueType();
        }

        return $found;
    }

    /**
     * The depths of a type at which a template is the key type, or the value itself.
     *
     * @return list<int>
     */
    private function templateKeyDepths(Type $type, string $template, bool $whole = false): array
    {
        $found = [];
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $type = TypeCombinator::removeNull($type);
            if ($whole && $template === $this->templateName($type)) {
                return [...$found, $depth];
            }

            if (!$type->isIterable()->yes()) {
                break;
            }

            if (!$whole && $template === $this->templateName($type->getIterableKeyType())) {
                $found[] = $depth;
            }

            $type = $type->getIterableValueType();
        }

        return $found;
    }

    /**
     * The name of the type when it is a template of a function or method, as
     * any in a callee's parameters is the callee's own; null for a template
     * of a class, which the object it is called on resolves, or any other type.
     */
    private function templateName(Type $type): ?string
    {
        $name = null;
        TypeTraverser::map($type, static function (Type $inner) use (&$name): Type {
            if ($inner instanceof TemplateType && null !== $inner->getScope()->getFunctionName()) {
                $name = $inner->getName();
            }

            return $inner;
        });

        return $name;
    }

    /** Whether a template appears in the variant's return type, or in a parameter's type or out type other than the one named. */
    private function templateElsewhere(string $template, ParametersAcceptor $variant, string $parameterName): bool
    {
        $types = [$variant->getReturnType()];
        foreach ($variant->getParameters() as $parameter) {
            if ($parameter->getName() !== $parameterName) {
                $types[] = $parameter->getType();
            }

            if ($parameter instanceof ExtendedParameterReflection && $parameter->getOutType() instanceof Type) {
                $types[] = $parameter->getOutType();
            }
        }

        $found = false;
        foreach ($types as $type) {
            TypeTraverser::map($type, static function (Type $inner, callable $traverse) use (&$found, $template): Type {
                if ($inner instanceof TemplateType && $template === $inner->getName()) {
                    $found = true;
                }

                return $traverse($inner);
            });
        }

        return $found;
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

    /**
     * The declared type of a property, or of an element of one at its depth, with how it is written.
     *
     * @return array{Type, string}|null
     */
    private function declaredTargetType(Expr $target, Scope $scope): ?array
    {
        $levels = 0;
        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
            ++$levels;
        }

        $type = $this->declaredPropertyType($target, $scope);
        for ($level = 0; $type instanceof Type && $level < $levels; ++$level) {
            $type = TypeCombinator::removeNull($type);
            $type = $type->isIterable()->yes() ? $type->getIterableValueType() : null;
        }

        return $type instanceof Type ? [$type, $this->propertyName($target) . str_repeat(self::NESTED, $levels)] : null;
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
     * any of them through an operator or a branch, handed through a function
     * or class that keeps their keys, or held in a literal, where the depth
     * goes below 0 by the levels it is nested.
     *
     * @return list<array{string, int}>
     */
    private function localArrays(Expr $expr, Scope $scope): array
    {
        $branches = $this->branches($expr);
        if (null !== $branches) {
            $locals = [];
            foreach ($branches as $branch) {
                $locals = [...$locals, ...$this->localArrays($branch, $scope)];
            }

            return $locals;
        }

        if ($expr instanceof CallLike) {
            $locals = [];
            foreach ($this->keptArguments($expr, $scope) as [$kept, $shift]) {
                foreach ($this->localArrays($kept, $scope) as [$variable, $depth]) {
                    $locals[] = [$variable, $depth - $shift];
                }
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

        $local = $this->target($expr);
        if (null === $local || array_any($local[1], static fn (?Expr $key): bool => !$key instanceof Expr)) {
            return [];
        }

        return [[$local[0], \count($local[1])]];
    }

    /**
     * A local array handed to a place whose declared type has string keys,
     * at the depths the place declares; the keys an expression makes without
     * an element write are judged there as they stand.
     *
     * @param list<int> $excluded depths whose keys the place takes as a template, declaring nothing
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function declaredUse(Node $node, Expr $value, Type $declared, string $text, Scope $scope, array $excluded = []): array
    {
        $depths = array_values(array_diff($this->stringKeyedDepths($declared), $excluded));
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
        foreach ($this->madeKeys($value, 0, $scope) as [$depth, $key, $target, $unless]) {
            if (\in_array($depth, $depths, true)) {
                $records[] = $this->record(self::DECLARED, $scope, $node, $target . str_repeat(self::NESTED, $depth), [$depth], $reason, key: $key, unless: $unless);
            }
        }

        return $records;
    }

    /**
     * An array whose keys code reads while PHPStan types them string, at a
     * depth: a local one, or, unless it is spread into a literal judged where
     * it goes, one made where it stands.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysRead(Node $node, Expr $value, string $text, Scope $scope, bool $judgeMade = true, int $at = 0): array
    {
        if (!\in_array($at, $this->stringKeyedDepths($scope->getType($value)), true)) {
            return [];
        }

        $records = [];
        foreach ($this->localArrays($value, $scope) as [$variable, $depth]) {
            if ($depth + $at >= 0) {
                $records[] = $this->record(self::USE, $scope, $node, $variable, [$depth + $at], $text);
            }
        }

        return $judgeMade ? [...$records, ...$this->madeUse($node, $value, \sprintf('line %d %s', $node->getStartLine(), $text), $scope, $at)] : $records;
    }

    /**
     * Each variable `global` names, whose writes and uses every function that
     * so names it shares.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function globals(Global_ $global, Scope $scope): array
    {
        $records = [];
        foreach ($global->vars as $variable) {
            if ($variable instanceof Variable && \is_string($variable->name)) {
                $records[] = $this->record(self::GLOBAL, $scope, $global, $variable->name);
            }
        }

        return $records;
    }

    /**
     * Each inline `@var` on a statement asserting string keys that may be all
     * digits over a value whose keys at that depth PHPStan does not know to be
     * strings. A variable the statement binds (assigned, destructured, a
     * foreach value or key, a static) is judged against the value it binds; a
     * variable it does not is recorded HELD, for the rule to judge against
     * every write of it that may reach the statement.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function asserted(Stmt $statement, Scope $scope): array
    {
        $doc = $statement->getDocComment();
        if (!$doc instanceof Doc) {
            return [];
        }

        $bound    = $this->boundBy($statement, $scope);
        $assigned = $statement instanceof Expression && $statement->expr instanceof Assign && $statement->expr->var instanceof Variable && \is_string($statement->expr->var->name)
            ? $statement->expr->var->name
            : null;

        $tags = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $scope->getClassReflection()?->getName(),
            $scope->getTraitReflection()?->getName(),
            $scope->getFunction()?->getName(),
            $doc->getText(),
        )->getVarTags();

        $records = [];
        foreach ($tags as $name => $tag) {
            $variable = \is_string($name) ? $name : $assigned;
            if (null === $variable || self::THIS === $variable) {
                continue;
            }

            $records = [...$records, ...$this->assertedKeys($statement, $variable, $tag->getType(), $bound[$variable] ?? null, $scope)];
        }

        return $records;
    }

    /**
     * The variables a statement binds, each with the type of what it binds it to.
     *
     * @return array<array-key, Type>
     */
    private function boundBy(Stmt $statement, Scope $scope): array
    {
        $bound = [];
        if ($statement instanceof Expression && ($statement->expr instanceof Assign || $statement->expr instanceof AssignRef)) {
            $target = $statement->expr->var;
            $value  = $scope->getType($statement->expr->expr);
            if ($target instanceof Variable && \is_string($target->name)) {
                $bound[$target->name] = $value;
            } elseif ($target instanceof List_ || $target instanceof Array_) {
                foreach ($this->destructured($target, $value, $scope) as [$variable, , $element]) {
                    $bound[$variable] = $element;
                }
            }
        } elseif ($statement instanceof Foreach_) {
            $iterated = $scope->getType($statement->expr);
            if ($statement->keyVar instanceof Variable && \is_string($statement->keyVar->name)) {
                $bound[$statement->keyVar->name] = $iterated->getIterableKeyType();
            }

            if ($statement->valueVar instanceof Variable && \is_string($statement->valueVar->name)) {
                $bound[$statement->valueVar->name] = $iterated->getIterableValueType();
            } elseif ($statement->valueVar instanceof List_ || $statement->valueVar instanceof Array_) {
                foreach ($this->destructured($statement->valueVar, $iterated->getIterableValueType(), $scope) as [$variable, , $element]) {
                    $bound[$variable] = $element;
                }
            }
        } elseif ($statement instanceof Static_) {
            foreach ($statement->vars as $static) {
                if (\is_string($static->var->name) && $static->default instanceof Expr) {
                    $bound[$static->var->name] = $scope->getType($static->default);
                }
            }
        }

        return $bound;
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function assertedKeys(Stmt $statement, string $variable, Type $declared, ?Type $actual, Scope $scope): array
    {
        $records = [];
        $known   = $actual instanceof Type ? $this->knownDepths($actual) : [];
        foreach ($this->stringKeyedDepths($declared) as $depth) {
            $declaredKey = $this->keyTypeAt($declared, $depth);
            if (!$declaredKey instanceof Type || !$this->typeMayBeAllDigits($declaredKey) || \in_array($depth, $known, true)) {
                continue;
            }

            $records[] = $this->record(
                $actual instanceof Type ? self::ASSERTED : self::HELD,
                $scope,
                $statement,
                '$' . $variable . str_repeat(self::NESTED, $depth),
                [$depth],
                \sprintf('line %d asserts it %s in an inline @var over keys PHPStan does not know', $statement->getStartLine(), $declared->describe(VerbosityLevel::typeOnly())),
                key: $variable,
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
            $declared = $function?->getReturnType();
            if (true === $function?->isGenerator()) {
                $declared = $declared?->getTemplateType(Generator::class, 'TReturn');
            }
        }

        return $declared instanceof Type ? $this->declaredUse($return, $return->expr, $declared, 'returns it as', $scope) : [];
    }

    /**
     * A value a generator yields, taken as the value type its return type declares.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function yielded(Yield_ $yield, Scope $scope): array
    {
        if (!$yield->value instanceof Expr) {
            return [];
        }

        $declared = $scope->isInAnonymousFunction() ? $scope->getAnonymousFunctionReturnType() : $scope->getFunction()?->getReturnType();
        if (!$declared instanceof Type || !$declared->isIterable()->yes()) {
            return [];
        }

        return $this->declaredUse($yield, $yield->value, $declared->getIterableValueType(), 'yields it as', $scope);
    }

    /**
     * A foreach reads the keys of what it iterates; over an array, its key
     * variable holds a key PHP made, for as long as the loop body leaves it
     * alone; its value variable holds the elements of what it iterates, by
     * reference their very elements; and PHPStan knew the value's keys at the
     * depths it typed them.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function iterated(Foreach_ $foreach, Scope $scope): array
    {
        $records = [...$this->elementCopies($foreach, $scope), ...$this->foreachBindings($foreach, $scope)];
        if (!$foreach->keyVar instanceof Expr) {
            return $records;
        }

        $records = [...$records, ...$this->keysRead($foreach, $foreach->expr, 'reads its keys with foreach', $scope)];
        if ($foreach->keyVar instanceof Variable && \is_string($foreach->keyVar->name) && $scope->getType($foreach->expr)->isArray()->yes()
                                                 && !$this->mayRebind($foreach->keyVar->name, $scope, ...$foreach->stmts)) {
            $records[] = $this->record(self::KEY_BINDING, $scope, $foreach, $foreach->keyVar->name);
        }

        return $records;
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function elementCopies(Foreach_ $foreach, Scope $scope): array
    {
        $targets = [];
        if ($foreach->valueVar instanceof Variable && \is_string($foreach->valueVar->name)) {
            $targets[] = [$foreach->valueVar->name, 1];
        } elseif ($foreach->valueVar instanceof List_ || $foreach->valueVar instanceof Array_) {
            foreach ($this->destructured($foreach->valueVar, new MixedType(), $scope, 2) as [$variable, $depth]) {
                $targets[] = [$variable, $depth];
            }
        }

        $records = [];
        foreach ($this->localArrays($foreach->expr, $scope) as [$source, $depth]) {
            foreach ($targets as [$target, $below]) {
                $records[] = $this->record(self::COPY, $scope, $foreach, $source, [$depth + $below], key: $target);
                if ($foreach->byRef) {
                    $records[] = $this->record(self::COPY, $scope, $foreach, $target, [-$depth - $below], key: $source);
                }
            }
        }

        return $records;
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function foreachBindings(Foreach_ $foreach, Scope $scope): array
    {
        $iterated = $scope->getType($foreach->expr);
        $records  = [];
        if ($foreach->keyVar instanceof Variable && \is_string($foreach->keyVar->name)) {
            $records[] = $this->record(self::BINDING, $scope, $foreach->keyVar, $foreach->keyVar->name, $this->knownDepths($iterated->getIterableKeyType()));
        }

        if ($foreach->byRef) {
            return $records;
        }

        if ($foreach->valueVar instanceof Variable && \is_string($foreach->valueVar->name)) {
            $records[] = $this->record(self::BINDING, $scope, $foreach->valueVar, $foreach->valueVar->name, $this->knownDepths($iterated->getIterableValueType()));
        } elseif ($foreach->valueVar instanceof List_ || $foreach->valueVar instanceof Array_) {
            $records = [...$records, ...$this->destructuredBindings($foreach->valueVar, $iterated->getIterableValueType(), $scope)];
        }

        return $records;
    }

    /**
     * What PHPStan knew of each parameter and imported variable where the
     * function-like starts, from its declared type and, for an import, the
     * variable imported; and each by-reference parameter, which hands the
     * array back to the caller as its declared type.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function parameters(ClassMethod|Function_|Closure|ArrowFunction $function, Scope $scope): array
    {
        $callee = $this->declaredFunction($function, $scope);
        if (null === $callee) {
            return [];
        }

        [$key, $declared] = $callee;
        $records          = [];
        foreach ($function->getParams() as $index => $param) {
            $reflection = $declared[$index] ?? null;
            if (!$param->var instanceof Variable || !\is_string($param->var->name) || !$reflection instanceof ParameterReflection) {
                continue;
            }

            $records[] = $this->record(self::BINDING, $scope, $param->var, $param->var->name, $this->knownDepths($reflection->getType()));
            if ($param->byRef) {
                $out     = $reflection instanceof ExtendedParameterReflection ? $reflection->getOutType() ?? $reflection->getType() : $reflection->getType();
                $records = [...$records, ...$this->declaredUse($param, $param->var, $out, 'hands it back by reference as', $scope)];
            }
        }

        if ($function instanceof Closure) {
            foreach ($function->uses as $use) {
                if (\is_string($use->var->name) && !$use->byRef) {
                    $records[] = $this->record(self::BINDING, $scope, $use->var, $use->var->name, $this->knownDepths($scope->getType($use->var)));
                }
            }
        }

        return null === $key ? $records : array_map(static fn (array $record): array => ['functionKey' => $key] + $record, $records);
    }

    /**
     * How a function or method is named in the records made inside it, null
     * for a closure, which is named as the function around it; and the
     * parameters it declares. Null where PHPStan does not know it.
     *
     * @return array{string|null, list<ParameterReflection>}|null
     */
    private function declaredFunction(ClassMethod|Function_|Closure|ArrowFunction $function, Scope $scope): ?array
    {
        if ($function instanceof ClassMethod) {
            $class = $scope->getClassReflection();
            if (!$class instanceof \PHPStan\Reflection\ClassReflection || !$class->hasNativeMethod($function->name->toString())) {
                return null;
            }

            $method = $class->getNativeMethod($function->name->toString());

            return [$class->getName() . '::' . $method->getName(), $method->getVariants()[0]->getParameters()];
        }

        if ($function instanceof Function_) {
            $name = new Name($function->namespacedName?->toString() ?? $function->name->toString());
            if (!$this->reflectionProvider->hasFunction($name, $scope)) {
                return null;
            }

            $reflection = $this->reflectionProvider->getFunction($name, $scope);

            return ['::' . $reflection->getName(), $reflection->getVariants()[0]->getParameters()];
        }

        $acceptors = $scope->getType($function)->getCallableParametersAcceptors($scope);

        return [] === $acceptors ? null : [null, $acceptors[0]->getParameters()];
    }

    /**
     * Each use of a variable handed to iterator_to_array() other than that, a
     * foreach over it, or an assignment to it: a call that may change its
     * flags or hand it on.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function escapes(Scope $scope, Node ...$statements): array
    {
        $finder  = new NodeFinder();
        $allowed = [];
        $names   = [];
        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof FuncCall || $node instanceof Foreach_ || $node instanceof Assign) as $node) {
            $variable = match (true) {
                $node instanceof FuncCall => $this->isIteratorToArray($node) ? $node->getArgs()[0]->value ?? null : null,
                $node instanceof Foreach_ => $node->expr,
                $node instanceof Assign   => $node->var,
                default                   => null,
            };
            if ($variable instanceof Variable && \is_string($variable->name)) {
                $allowed[spl_object_id($variable)] = true;
                if ($node instanceof FuncCall) {
                    $names[$variable->name] = true;
                }
            }
        }

        $records = [];
        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof Variable && \is_string($node->name) && isset($names[$node->name]) && !isset($allowed[spl_object_id($node)])) as $use) {
            if ($use instanceof Variable && \is_string($use->name)) {
                $records[] = $this->record(self::ESCAPE, $scope, $use, $use->name);
            }
        }

        return $records;
    }

    /**
     * Each statement, in each statement list of a body outside its closures,
     * that writes the whole of a variable on every path on to the next.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function kills(Scope $scope, Stmt ...$statements): array
    {
        $last    = array_last($statements);
        $records = [];
        foreach ($statements as $statement) {
            foreach ($this->killed($statement) ?? [] as $name) {
                $records[] = $this->record(self::KILL, $scope, $statement, $name, [$last?->getEndFilePos() ?? -1]);
            }

            foreach ($this->innerLists($statement) as $list) {
                $records = [...$records, ...$this->kills($scope, ...$list)];
            }
        }

        return $records;
    }

    /**
     * The statement lists a statement holds.
     *
     * @return list<list<Stmt>>
     */
    private function innerLists(Stmt $statement): array
    {
        $lists = match (true) {
            $statement instanceof If_      => [$statement->stmts, ...array_map(static fn (ElseIf_ $elseIf): array => $elseIf->stmts, $statement->elseifs), $statement->else->stmts ?? []],
            $statement instanceof For_, $statement instanceof Foreach_, $statement instanceof While_, $statement instanceof Do_,
            $statement instanceof Block    => [$statement->stmts],
            $statement instanceof Switch_  => array_map(static fn (Case_ $case): array => $case->stmts, $statement->cases),
            $statement instanceof TryCatch => [$statement->stmts, ...array_map(static fn (Catch_ $catch): array => $catch->stmts, $statement->catches), $statement->finally->stmts ?? []],
            default                        => [],
        };

        return array_values(array_map(array_values(...), $lists));
    }

    /**
     * The variables a statement writes whole on every path on to the next
     * statement: an assignment to one, or an if with an else whose every
     * branch writes it or never goes on; null where no path goes on.
     *
     * @return list<string>|null
     */
    private function killed(Stmt $statement): ?array
    {
        if ($statement instanceof Return_ || $statement instanceof Continue_ || $statement instanceof Break_
                                          || ($statement instanceof Expression && ($statement->expr instanceof Throw_ || $statement->expr instanceof Exit_))) {
            return null;
        }

        if ($statement instanceof Expression && $statement->expr instanceof Assign && $statement->expr->var instanceof Variable && \is_string($statement->expr->var->name)) {
            return [$statement->expr->var->name];
        }

        if ($statement instanceof TryCatch) {
            return $this->tryKilled($statement);
        }

        if ($statement instanceof Switch_) {
            return $this->switchKilled($statement);
        }

        if (!$statement instanceof If_ || !$statement->else instanceof Else_) {
            return [];
        }

        return $this->commonKilled(...[$statement->stmts, ...array_map(static fn (ElseIf_ $elseIf): array => $elseIf->stmts, $statement->elseifs), $statement->else->stmts]);
    }

    /**
     * The variables each of several alternative statement lists that goes
     * on past its end writes whole; null where none goes on.
     *
     * @param array<array-key, Stmt> ...$branches
     *
     * @return list<string>|null
     */
    private function commonKilled(array ...$branches): ?array
    {
        $common = null;
        foreach ($branches as $branch) {
            $names = $this->listKilled(...array_values($branch));
            if (null !== $names) {
                $common = null === $common ? $names : array_values(array_intersect($common, $names));
            }
        }

        return $common;
    }

    /**
     * What a try writes whole on every path on: what the try and each catch
     * that goes on all write, and what the finally writes.
     *
     * @return list<string>|null
     */
    private function tryKilled(TryCatch $try): ?array
    {
        $finally = $try->finally instanceof Finally_ ? $this->listKilled(...array_values($try->finally->stmts)) : [];
        $common  = $this->commonKilled($try->stmts, ...array_map(static fn (Catch_ $catch): array => $catch->stmts, $try->catches));

        return null === $finally || null === $common ? null : array_values(array_unique([...$common, ...$finally]));
    }

    /**
     * What a switch with a default writes whole on every path out of it:
     * entered at any case, it reaches a write of the variable, or a
     * statement that never goes on, before any break and before it falls
     * out of the last case.
     *
     * @return list<string>
     */
    private function switchKilled(Switch_ $switch): array
    {
        if (!array_any($switch->cases, static fn (Case_ $case): bool => !$case->cond instanceof Expr)) {
            return [];
        }

        $statements = [];
        $entries    = [];
        foreach ($switch->cases as $case) {
            $entries[]  = \count($statements);
            $statements = [...$statements, ...array_values($case->stmts)];
        }

        $names = [];
        foreach ($statements as $statement) {
            $names = [...$names, ...$this->killed($statement) ?? []];
        }

        return array_values(array_filter(
            array_unique($names),
            fn (string $name): bool => array_all($entries, fn (int $entry): bool => $this->killedBeforeLeaving($name, ...\array_slice($statements, $entry))),
        ));
    }

    /** Whether a run of a switch's statements writes the variable whole, or never goes on, before a break leaves it. */
    private function killedBeforeLeaving(string $name, Stmt ...$statements): bool
    {
        foreach ($statements as $statement) {
            if ($this->breaksOut($statement)) {
                return false;
            }

            $killed = $this->killed($statement);
            if (null === $killed || \in_array($name, $killed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a statement of a switch's case may break, or continue, to the
     * statement after the switch: one whose level, counted out through the
     * loops and switches around it, is the switch's. One that goes no
     * further stays in the switch, and one that goes further leaves the loop
     * around it too, and never reaches that statement. A break in a closure
     * or class is its own.
     */
    private function breaksOut(Stmt $statement): bool
    {
        $finder  = new NodeFinder();
        $within  = static fn (Node $outer, Node $inner): bool => $outer->getStartFilePos() <= $inner->getStartFilePos() && $inner->getEndFilePos() <= $outer->getEndFilePos();
        $nested  = $finder->find([$statement], static fn (Node $node): bool => $node instanceof For_ || $node instanceof Foreach_ || $node instanceof While_ || $node instanceof Do_ || $node instanceof Switch_);
        $foreign = $finder->find([$statement], static fn (Node $node): bool => $node instanceof FunctionLike || $node instanceof ClassLike);

        return array_any(
            $finder->find([$statement], static fn (Node $node): bool => $node instanceof Break_ || $node instanceof Continue_),
            static function (Node $break) use ($nested, $foreign, $within): bool {
                if ((!$break instanceof Break_ && !$break instanceof Continue_) || array_any($foreign, static fn (Node $outer): bool => $within($outer, $break))) {
                    return false;
                }

                $levels = 1 + \count(array_filter($nested, static fn (Node $outer): bool => $within($outer, $break)));

                return $break->num instanceof Expr ? !$break->num instanceof Int_ || $break->num->value === $levels : 1 === $levels;
            },
        );
    }

    /**
     * The variables a statement list writes whole on every path through it, null where none goes on past it.
     *
     * @return list<string>|null
     */
    private function listKilled(Stmt ...$statements): ?array
    {
        $names = [];
        foreach ($statements as $statement) {
            $killed = $this->killed($statement);
            if (null === $killed) {
                return null;
            }

            $names = [...$names, ...$killed];
        }

        return $names;
    }

    private function isIteratorToArray(FuncCall $call): bool
    {
        return self::ITERATOR_TO_ARRAY === $this->functionName($call) && isset($call->getArgs()[0]) && !$call->getArgs()[0]->unpack;
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
            $node instanceof Include_, $node instanceof Eval_  => true,
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
     * Each local variable, or element of one, a call may take by reference,
     * with the depths whose keys PHPStan types string once it returns. Where
     * the callee or the parameter cannot be resolved, or the argument is
     * spread, no depth is known.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function byReference(CallLike $call, Scope $scope): array
    {
        $args       = $call->getArgs();
        $callee     = $this->callee($call, $scope);
        $parameters = null === $callee ? [] : ParametersAcceptorSelector::selectFromArgs($scope, $args, $callee->getVariants())->getParameters();
        $records    = [];
        foreach ($args as $index => $arg) {
            $local = $this->target($arg->value);
            if (null === $local || str_starts_with($local[0], self::GLOBALS)) {
                continue;
            }

            $receiving = \array_slice($parameters, $index);
            $last      = array_last($parameters);
            if (null !== $last && $last->isVariadic()) {
                $receiving[] = $last;
            }

            if ($arg->unpack && null !== $callee && array_all($receiving, static fn (ParameterReflection $parameter): bool => $parameter->passedByReference()->no())) {
                continue;
            }

            $parameter = $arg->unpack ? null : $this->parameterFor($arg, $index, ...$parameters);
            if ($parameter instanceof ParameterReflection && $parameter->passedByReference()->no()) {
                continue;
            }

            $depths    = null !== $callee && $parameter instanceof ParameterReflection ? $this->referenceDepths($call, $callee, $parameter, $arg->value, $scope, ...$local[1]) : [];
            $records[] = $this->record(self::BY_REFERENCE, $scope, $arg->value, $local[0], $depths);
        }

        return $records;
    }

    /**
     * The depths whose keys PHPStan types string in a variable once an
     * argument made of it, at the keys given, is written by reference: as
     * PHPStan writes it back, then as one of PHP's array functions leaves it.
     *
     * @return list<int>
     */
    private function referenceDepths(CallLike $call, FunctionReflection|ExtendedMethodReflection $callee, ParameterReflection $parameter, Expr $value, Scope $scope, ?Expr ...$keys): array
    {
        $out = $this->writtenBack($callee, $parameter, $scope->getType($value));
        if ($callee instanceof FunctionReflection && $call instanceof FuncCall && self::ARRAY_PARAMETER === $parameter->getName()) {
            $out = $this->arrayFunctionEffect(strtolower($callee->getName()), $out, $scope, ...$call->getArgs()) ?? $out;
        }

        $known = [];
        foreach (array_values($keys) as $depth => $key) {
            if ($key instanceof Expr && $scope->getType($key)->isString()->yes()) {
                $known[] = $depth;
            }
        }

        foreach ($this->knownDepths($out) as $depth) {
            $known[] = $depth + \count($keys);
        }

        return $known;
    }

    /**
     * What PHPStan writes back into an argument taken by reference: nothing
     * for a parameter that only reads and changes its argument, which keeps
     * the type it had; otherwise the parameter's out type, or for a function
     * or method of the project's own its declared type, or for one of PHP's
     * own `mixed`.
     */
    private function writtenBack(FunctionReflection|ExtendedMethodReflection $callee, ParameterReflection $parameter, Type $passed): Type
    {
        if (!$parameter->passedByReference()->createsNewVariable()) {
            return $passed;
        }

        if ($parameter instanceof ExtendedParameterReflection && $parameter->getOutType() instanceof Type) {
            return $parameter->getOutType();
        }

        $builtin = $callee instanceof FunctionReflection ? $callee->isBuiltin() : $callee->getDeclaringClass()->isBuiltin();

        return $builtin ? new MixedType() : $parameter->getType();
    }

    /**
     * The type PHPStan gives the array one of PHP's array functions changes
     * in place: a sort that renumbers makes it a list, a sort that keeps keys
     * keeps them, a function that adds values adds int keys; null for any
     * other function.
     */
    private function arrayFunctionEffect(string $function, Type $array, Scope $scope, Arg ...$args): ?Type
    {
        $count = \count($args);

        return match (true) {
            \in_array($function, self::LIST_MAKING_SORTS, true)                                                         => $array->shuffleArray(),
            \in_array($function, self::KEY_KEEPING_SORTS, true)                                                         => $array->makeListMaybe(),
            self::ARRAY_POP   === $function                                                                             => $array->popArray(),
            self::ARRAY_SHIFT === $function                                                                             => $array->shiftArray(),
            \in_array($function, self::APPENDING_FUNCTIONS, true) && $count >= self::WITH_VALUES                        => TypeCombinator::union($array, new ArrayType(new IntegerType(), $this->appendedValues($scope, ...\array_slice($args, 1)))),
            self::ARRAY_SPLICE === $function                      && $count >= self::WITH_VALUES                        => $array->spliceArray(
                $scope->getType($args[1]->value),
                isset($args[2]) ? $scope->getType($args[2]->value) : new NullType(),
                isset($args[3]) ? $scope->getType($args[3]->value) : new ConstantArrayType([], []),
            ),
            default                                                                                                     => null,
        };
    }

    /** The values array_push() or array_unshift() adds, spread ones included. */
    private function appendedValues(Scope $scope, Arg ...$args): Type
    {
        $types = [];
        foreach ($args as $arg) {
            $type    = $scope->getType($arg->value);
            $types[] = $arg->unpack ? $type->getIterableValueType() : $type;
        }

        return TypeCombinator::union(...$types);
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

        $function = $this->functionName($call);
        if (null !== $function && $call instanceof FuncCall) {
            $records = [...$records, ...$this->keysReadBy($function, $scope, $call, ...$args), ...$this->keysNeverRead($call, $scope)];
        }

        $callee = $this->callee($call, $scope);
        if (null === $callee) {
            return $records;
        }

        [, $read, $excluded] = $this->genericFlows($call, $scope);
        if (null === $function || !$this->isListed($function)) {
            foreach ($read as [$value, $depth]) {
                $records = [...$records, ...$this->keysRead($call, $value, 'reads its keys with ' . $this->calleeName($call), $scope, true, $depth)];
            }
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $args, $callee->getVariants())->getParameters();
        foreach ($args as $index => $arg) {
            $parameter = $arg->unpack ? null : $this->parameterFor($arg, $index, ...$parameters);
            if ($parameter instanceof ParameterReflection) {
                $records = [...$records, ...$this->declaredUse($call, $arg->value, $parameter->getType(), 'passes it to ' . $this->calleeName($call) . ' as', $scope, $excluded[$index] ?? [])];
            }
        }

        return $records;
    }

    /**
     * `count(array_keys($array))` and `array_map(strval(...), array_keys($array))`:
     * the keys are counted, or made strings, and never reach code as they are.
     *
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysNeverRead(FuncCall $call, Scope $scope): array
    {
        $function = $this->functionName($call);
        $args     = $call->getArgs();
        $inner    = match (true) {
            \in_array($function, self::COUNTING, true)                                                                      => $args[0] ?? null,
            self::ARRAY_MAP === $function && self::ARRAY_MAP_WITH_ONE_ARRAY === \count($args) && $this->isStrval($args[0])  => $args[1],
            default                                                                                                         => null,
        };

        return $inner instanceof Arg && !$inner->unpack && $inner->value instanceof FuncCall && self::ARRAY_KEYS === $this->functionName($inner->value)
            ? [$this->record(self::UNREAD, $scope, $inner->value)]
            : [];
    }

    /** Whether a callback is PHP's strval(), as a first-class callable or by name. */
    private function isStrval(Arg $callback): bool
    {
        $value = $callback->value;
        if ($value instanceof FuncCall) {
            return $value->isFirstClassCallable() && $value->name instanceof Name && self::STRVAL === $value->name->toLowerString();
        }

        return $value instanceof String_ && self::STRVAL === strtolower($value->value);
    }

    /**
     * @return list<NumericStringArrayKeyRecord>
     */
    private function keysReadBy(string $function, Scope $scope, FuncCall $call, Arg ...$args): array
    {
        if ($this->preservesKeys($function, $scope, ...$args) || $this->callbackTakesNoKey($function, ...$args) || $this->writesNoKey($function, $scope, ...$args)) {
            return [];
        }

        if (\array_key_exists($function, self::KEY_READING_FUNCTIONS)) {
            $positions = self::KEY_READING_FUNCTIONS[$function];
        } elseif (self::ARRAY_FILTER === $function && \count($args) >= self::ARRAY_FILTER_WITH_MODE) {
            $positions = [0];
        } else {
            return [];
        }

        $text    = 'reads its keys with ' . $this->calleeName($call);
        $records = [];
        foreach ($this->argumentsAt($positions, ...$args) as $value) {
            $records = [...$records, ...$this->keysRead($call, $value, $text, $scope)];
        }

        return $records;
    }

    /**
     * Whether json_encode() is told to write every array as an object, or
     * http_build_query() is given no numeric prefix: an int key is then
     * written as its string would be.
     */
    private function writesNoKey(string $function, Scope $scope, Arg ...$args): bool
    {
        $option = $args[1] ?? null;
        if (self::JSON_ENCODE === $function) {
            $flags = $option instanceof Arg && !$option->unpack ? $scope->getType($option->value)->getConstantScalarValues() : [];

            return [] !== $flags && array_all($flags, static fn (mixed $value): bool => \is_int($value) && 0 !== ($value & \JSON_FORCE_OBJECT));
        }

        if (self::HTTP_BUILD_QUERY === $function) {
            $prefixes = $option instanceof Arg && !$option->unpack ? $scope->getType($option->value)->getConstantScalarValues() : [null];

            return [] !== $prefixes && array_all($prefixes, static fn (mixed $value): bool => null === $value || '' === $value);
        }

        return false;
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
        if (!$callback instanceof Arg || !$callback->value instanceof Closure && !$callback->value instanceof ArrowFunction) {
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
