<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires variadic syntax when a single array-typed parameter is PHPDoc-annotated as list<T>.
 *
 * When a method has exactly one parameter declared as `array` but the PHPDoc annotates it
 * as `@param list<T> $param`, the intent is a homogeneous typed list. Use variadic syntax
 * `T ...$param` instead — it gives compile-time type safety without the docblock crutch.
 *
 * Only triggers for methods with exactly one parameter: these are the safest to refactor
 * because callsites always pass a single spread argument.
 *
 * WRONG:
 *   /**
 *    * @param list<ProductEnquiryItem> $items
 *    * /
 *   public function render(array $items): string
 *
 * RIGHT:
 *   public function render(ProductEnquiryItem ...$items): string
 *
 * @implements Rule<ClassMethod>
 */
final readonly class RequireVariadicForSingleListParamRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.arrayListShouldBeVariadic';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (1 !== \count($node->params)) {
            return [];
        }

        $param = $node->params[0];

        if (!$this->isDeclaredAsArray($param->type)) {
            return [];
        }

        if ($param->variadic) {
            return [];
        }

        // Skip promoted constructor parameters — PHP does not allow promoted
        // properties to use variadic syntax, so the rule cannot apply.
        if (0 !== $param->flags) {
            return [];
        }

        // Skip parameters that carry PHP attributes — DI frameworks inject these
        // as plain arrays and variadic syntax is not compatible with DI injection.
        if ([] !== $param->attrGroups) {
            return [];
        }

        $docComment = $node->getDocComment();

        if (!$docComment instanceof \PhpParser\Comment\Doc) {
            return [];
        }

        if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
            return [];
        }

        $paramName = $param->var->name;

        if (!$this->hasListAnnotation($docComment->getText(), $paramName)) {
            return [];
        }

        $methodName = $node->name->toString();

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Method %s() has a single "array $%s" parameter annotated as @param list<T> in PHPDoc. '
                    . 'Use variadic syntax instead: (T ...$%s)',
                    $methodName,
                    $paramName,
                    $paramName,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function isDeclaredAsArray(mixed $type): bool
    {
        if ($type instanceof Identifier) {
            return 'array' === $type->name;
        }

        if ($type instanceof Node\Name) {
            return 'array' === $type->toString();
        }

        return false;
    }

    private function hasListAnnotation(string $docComment, string $paramName): bool
    {
        // Matches: @param list<T> $paramName or list<T<U>> $paramName (one level of nesting)
        $escapedName = preg_quote($paramName, '/');
        $pattern     = '/@param\s+list\s*<(?:[^<>]|<[^>]*>)*>\s+\$' . $escapedName . '\b/';

        return (bool)\Safe\preg_match($pattern, $docComment);
    }
}
