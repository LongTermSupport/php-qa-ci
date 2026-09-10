<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method or function with a parameter declared `array` whose `@param` docblock types
 * it as `list<T>` or `non-empty-list<T>` could be declared `T ...$name` instead. A native
 * variadic parameter is checked by the engine at every call site, including ones PHPStan
 * cannot see into (a consumer project's override calling into this library), and needs no
 * docblock that can drift from the native type.
 *
 * `array<T>` is deliberately NOT treated as list-shaped. PHPStan expands it to
 * `array<mixed, mixed>` — the single argument constrains the VALUE type and says nothing
 * about the keys — so an `array<T>` parameter may well be a map, and converting one to a
 * variadic silently discards its keys. Only `list` states the shape a variadic provides.
 *
 * The parameter does NOT have to be last already. A variadic must be final, so one that
 * is not can be moved there and then converted; the message says so. Only one parameter
 * per signature is reported, since a signature can hold only one variadic, and the one
 * nearest the end is chosen because it moves the least.
 *
 * Not flagged, because none of them can become a plain variadic:
 *   - the parameter is promoted (PHP forbids a variadic promoted property), by
 *     reference, or already variadic;
 *   - the signature already has a variadic, so its one slot is spent;
 *   - any parameter AFTER it carries a default. Moving past an optional parameter
 *     breaks callers outright: PHP rejects `f($a, name: $b, ...$args)` with "Cannot
 *     use argument unpacking after named arguments", and a library cannot see its
 *     consumers' call sites;
 *   - the `@param` type is anything other than `list<T>` / `non-empty-list<T>`, including
 *     `array<T>`, a map, `iterable<K, V>`, a shape, or no `@param` entry at all;
 *   - the method is `__construct` — PHPStan's own neon DI container passes each
 *     `arguments:` entry as one positional value, so a variadic constructor would
 *     silently re-interpret an array argument as the first element of a spread;
 *   - the method carries `#[DataProvider]` / `#[DataProviderExternal]` — PHPUnit
 *     passes each provider row's elements as separate arguments, so a `list<string>`
 *     element is ONE argument and a variadic would change what the row means;
 *   - the method implements an interface method or overrides a parent method — the
 *     signature belongs to the supertype, not to this declaration.
 *
 * WRONG:
 *
 *   /** @param list<string> $baseArgs * /
 *   public function build(array $baseArgs): Process
 *
 * RIGHT:
 *   public function build(string ...$baseArgs): Process
 *
 * See: docs/phpstan-rules/require-variadic-over-array-parameter.md
 *
 * @implements Rule<FunctionLike>
 */
final readonly class RequireVariadicOverArrayParameterRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.variadicOverArrayParameter';

    private const string BALANCED_GENERIC = '(?:[^<>]|<[^<>]*>)*';

    private const string ARRAY_KEYWORD = 'array';

    private const string STRING_TYPE = 'string';

    private const string INT_TYPE = 'int';

    private const array LIST_LIKE_KEYWORDS = ['non-empty-list', 'list'];

    private const string DATA_PROVIDER = \PHPUnit\Framework\Attributes\DataProvider::class;

    private const string DATA_PROVIDER_EXTERNAL = \PHPUnit\Framework\Attributes\DataProviderExternal::class;

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Function_) {
            return [];
        }

        if ($node instanceof ClassMethod && '__construct' === $node->name->toLowerString()) {
            return [];
        }

        $params = $node->getParams();

        /*
         * A signature can hold one variadic and only in final position, so a
         * method that already has one has spent it and nothing else in that
         * signature is convertible.
         */
        foreach ($params as $param) {
            if ($param->variadic) {
                return [];
            }
        }

        $docComment = $node->getDocComment();
        if (!$docComment instanceof Doc) {
            return [];
        }

        if ($node instanceof ClassMethod && $this->hasDataProviderAttribute($node)) {
            return [];
        }

        if ($node instanceof ClassMethod && $this->overridesSupertype($node, $scope)) {
            return [];
        }

        $candidate = $this->lastConvertibleParam($docComment->getText(), ...$params);
        if (null === $candidate) {
            return [];
        }

        [$paramName, $keyword, $elementType, $isLast] = $candidate;

        return [
            RuleErrorBuilder::message(\sprintf(
                'Parameter $%s of %s() is a %s<%s> in a docblock; %sdeclare it "%s ...$%s" so the engine checks it.',
                $paramName,
                $node->name->toString(),
                $keyword,
                $elementType,
                $isLast ? '' : 'move it to last and ',
                $this->nativeType($elementType),
                $paramName,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    /**
     * The convertible parameter nearest the end of the signature, or null when
     * there is none.
     *
     * Only one parameter can become the variadic, so a signature with several
     * list-shaped array parameters is reported once. The last one is chosen
     * because it moves the least: if it is already final, nothing else shifts.
     *
     * @return array{0: string, 1: string, 2: string, 3: bool}|null name, keyword, element type, already last
     */
    private function lastConvertibleParam(string $docText, Param ...$params): ?array
    {
        $lastIndex = \count($params) - 1;

        for ($index = $lastIndex; $index >= 0; --$index) {
            $param = $params[$index];
            if (!$this->isPlainArrayParam($param)) {
                continue;
            }

            if ($this->thisOrAnyLaterParamIsOptional($index, ...$params)) {
                continue;
            }

            if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
                continue;
            }

            $listType = $this->listLikeType($docText, $param->var->name);
            if (null === $listType) {
                continue;
            }

            return [$param->var->name, $listType[0], $listType[1], $index === $lastIndex];
        }

        return null;
    }

    /**
     * Whether this parameter or any after it carries a default. Either way the
     * parameter is not convertible, for two separate reasons.
     *
     * A LATER optional blocks the move to final position. PHP rejects
     * `f($a, name: $b, ...$args)` outright with "Cannot use argument unpacking
     * after named arguments", so every caller that names one of those optionals
     * stops compiling and the rest are forced to spell out defaults they did not
     * care about. A library cannot see its consumers' call sites.
     *
     * A default on the parameter ITSELF cannot survive the conversion at all: a
     * variadic is implicitly optional and empty, and PHP has no syntax for
     * `string ...$items = ['a']`. Converting one would silently drop a non-empty
     * default, changing what a caller passing nothing receives.
     */
    private function thisOrAnyLaterParamIsOptional(int $index, Param ...$params): bool
    {
        for ($at = $index, $total = \count($params); $at < $total; ++$at) {
            if ($params[$at]->default instanceof Node\Expr) {
                return true;
            }
        }

        return false;
    }

    /**
     * A docblock refinement such as `class-string` or `non-empty-string` has no
     * native spelling, so suggesting it verbatim would name a declaration that
     * does not parse. The native base type goes in the signature and the
     * refinement stays in the docblock, which is what the variadic form buys:
     * the engine checks the base, the docblock keeps the detail.
     */
    private function nativeType(string $elementType): string
    {
        return match (true) {
            str_ends_with($elementType, self::STRING_TYPE)   => self::STRING_TYPE,
            str_contains($elementType, self::INT_TYPE . '<') => self::INT_TYPE,
            'positive-int' === $elementType,
            'negative-int' === $elementType                  => self::INT_TYPE,
            default                                          => $elementType,
        };
    }

    private function isPlainArrayParam(Param $param): bool
    {
        if ($param->variadic || $param->byRef || $param->isPromoted()) {
            return false;
        }

        return $param->type instanceof Identifier && self::ARRAY_KEYWORD === $param->type->name;
    }

    /**
     * The matched keyword and its single element type, or null when no reportable
     * list-shaped @param exists for this parameter (absent, a map, a shape, ...).
     *
     * @return array{0: string, 1: string}|null
     */
    private function listLikeType(string $docText, string $paramName): ?array
    {
        $escapedName = preg_quote($paramName, '/');

        foreach (self::LIST_LIKE_KEYWORDS as $keyword) {
            $pattern = '/@param\s+(' . preg_quote($keyword, '/') . ')\s*<(' . self::BALANCED_GENERIC . ')>\s+\$' . $escapedName . '\b/';

            if (1 !== \Safe\preg_match($pattern, $docText, $matches)) {
                continue;
            }

            if (!isset($matches[2])) {
                continue;
            }

            $elementType = trim($matches[2]);
            if ('' === $elementType) {
                continue;
            }

            return [$keyword, $elementType];
        }

        return null;
    }

    /**
     * PHPStan runs PhpParser's name resolver before a rule sees the node, so an
     * attribute name is always fully qualified here however it was written at the
     * call site — imported, aliased or spelled out. Comparing the resolved name is
     * therefore both sufficient and exact; matching on the trailing segment would
     * additionally catch an unrelated `App\DataProvider` attribute.
     */
    private function hasDataProviderAttribute(ClassMethod $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if (self::DATA_PROVIDER === $name || self::DATA_PROVIDER_EXTERNAL === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    private function overridesSupertype(ClassMethod $node, Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();
        if (!$classReflection instanceof ClassReflection) {
            return false;
        }

        $methodName = $node->name->toString();
        $ancestors  = [...$classReflection->getParents(), ...array_values($classReflection->getInterfaces())];

        return array_any($ancestors, static fn (ClassReflection $ancestor): bool => $ancestor->hasNativeMethod($methodName));
    }
}
