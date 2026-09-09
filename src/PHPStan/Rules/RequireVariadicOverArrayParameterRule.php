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
 * A method or function whose LAST parameter is declared `array` and whose `@param`
 * docblock types that parameter as a homogeneous list — `list<T>`, `non-empty-list<T>`,
 * or `array<T>` with no key type — could be declared `T ...$name` instead. A native
 * variadic parameter is checked by the engine at every call site, including ones
 * PHPStan cannot see into (a consumer project's override calling into this library),
 * and needs no docblock that can drift from the native type.
 *
 * Not flagged, because none of them can become a plain variadic:
 *   - the parameter is promoted (PHP forbids a variadic promoted property), by
 *     reference, or already variadic;
 *   - the `@param` type is a map (`array<K, V>`, two type arguments), `iterable<K, V>`,
 *     a shape/object-like array type, or there is no `@param` entry for it at all;
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

    private const array LIST_LIKE_KEYWORDS = ['non-empty-list', 'list', self::ARRAY_KEYWORD];

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
        if ([] === $params) {
            return [];
        }

        $lastParam = $params[\count($params) - 1];

        if (!$this->isPlainArrayParam($lastParam)) {
            return [];
        }

        if (!$lastParam->var instanceof Variable || !\is_string($lastParam->var->name)) {
            return [];
        }

        $paramName = $lastParam->var->name;

        $docComment = $node->getDocComment();
        if (!$docComment instanceof Doc) {
            return [];
        }

        $listType = $this->listLikeType($docComment->getText(), $paramName);
        if (null === $listType) {
            return [];
        }

        if ($node instanceof ClassMethod && $this->hasDataProviderAttribute($node)) {
            return [];
        }

        if ($node instanceof ClassMethod && $this->overridesSupertype($node, $scope)) {
            return [];
        }

        [$keyword, $elementType] = $listType;

        return [
            RuleErrorBuilder::message(\sprintf(
                'Parameter $%s of %s() is a %s<%s> in a docblock; declare it "%s ...$%s" so the engine checks it.',
                $paramName,
                $node->name->toString(),
                $keyword,
                $elementType,
                $this->nativeType($elementType),
                $paramName,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
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

            if (self::ARRAY_KEYWORD === $keyword && $this->hasTopLevelComma($elementType)) {
                continue;
            }

            return [$keyword, $elementType];
        }

        return null;
    }

    /** A comma outside any nested `<...>` marks a two-argument map type, e.g. array<K, V>. */
    private function hasTopLevelComma(string $type): bool
    {
        $depth = 0;
        foreach (str_split($type) as $char) {
            if ('<' === $char) {
                ++$depth;
            } elseif ('>' === $char) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                return true;
            }
        }

        return false;
    }

    private function hasDataProviderAttribute(ClassMethod $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if (self::DATA_PROVIDER === $name || self::DATA_PROVIDER_EXTERNAL === $name) {
                    return true;
                }

                $shortName = $attr->name->getLast();
                if ('DataProvider' === $shortName || 'DataProviderExternal' === $shortName) {
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

        foreach ($ancestors as $ancestor) {
            if ($ancestor->hasNativeMethod($methodName)) {
                return true;
            }
        }

        return false;
    }
}
