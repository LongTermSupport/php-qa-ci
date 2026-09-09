<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A scalar parameter or return whose docblock enumerates a closed set of literal
 * values is an enum that never got declared.
 *
 * WRONG:
 *
 *   /** @param 'asc'|'desc' $direction * /
 *   public function sort(string $direction): void
 *
 * RIGHT:
 *   public function sort(SortDirectionEnum $direction): void
 *
 * The docblock states the domain, but nothing enforces it: any string passes the
 * native type, and the set is re-typed in every docblock that mentions it, so the
 * copies drift. A backed enum makes the set one declaration the engine enforces at
 * every call site. Remediation: docs/phpstan-rules/require-enum-over-literal-union.md
 *
 * Scope: `@param` and `@return` on any function-like whose native type is a scalar
 * that could carry the literals (`string`, `int`, their nullable/union forms, or no
 * native type at all). A single literal is a constant, not a set, and is not flagged.
 * A literal union nested inside a generic (`list<'a'|'b'>`) types the element, not
 * this scalar, and is left to the element's own declaration.
 *
 * LITERAL_UNION matches one docblock type expression: a `|`-union of quoted
 * string literals and/or integer literals (plus an optional `null`), optionally
 * parenthesised and/or `?`-prefixed, anchored so a union nested in a generic
 * never matches.
 *
 * @implements Rule<FunctionLike>
 */
final readonly class RequireEnumOverLiteralUnionRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.enumOverLiteralUnion';

    private const string LITERAL = "(?:'[^']*'|\"[^\"]*\"|-?\\d+)";

    private const string LITERAL_UNION = '(?:\??\(?' . self::LITERAL . '(?:\s*\|\s*(?:' . self::LITERAL . '|null))+\)?)';

    private const array SCALAR_NATIVE_TYPES = ['string', 'int', 'null'];

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $docComment = $node->getDocComment();

        if (!$docComment instanceof Doc) {
            return [];
        }

        $docText = $docComment->getText();
        $errors  = [];

        foreach ($node->getParams() as $param) {
            if (!$param->var instanceof Variable) {
                continue;
            }

            if (!\is_string($param->var->name)) {
                continue;
            }

            if (!$this->isScalarNativeType($param->type)) {
                continue;
            }

            $union = $this->literalUnionFor($docText, '@param', '\$' . preg_quote($param->var->name, '/'));

            if (null === $union) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf('@param %s $%s on %s', $union, $param->var->name, $this->describe($node)),
            );
        }

        if ($this->isScalarNativeType($node->getReturnType())) {
            $union = $this->literalUnionFor($docText, '@return', '');

            if (null !== $union) {
                $errors[] = $this->error(\sprintf('@return %s on %s', $union, $this->describe($node)));
            }
        }

        return $errors;
    }

    /**
     * The literal union written for the tag, or null when the tag is absent or its type
     * is not a closed literal set. `$suffix` is the escaped `$name` for @param and empty
     * for @return; the type is the whitespace-free token that follows the tag.
     */
    private function literalUnionFor(string $docText, string $tag, string $suffix): ?string
    {
        $pattern = '/' . preg_quote($tag, '/') . '\s+(' . self::LITERAL_UNION . ')(?=\s' . ('' === $suffix ? '' : '+' . $suffix . '\b') . ')/';

        if (1 !== \Safe\preg_match($pattern, $docText, $matches)) {
            return null;
        }

        $union = $matches[1] ?? null;
        if (null === $union) {
            return null;
        }

        // `'fixed'|null` is a constant, not a set: a closed set needs two literals.
        if (\Safe\preg_match_all('/' . self::LITERAL . '/', $union) < 2) {
            return null;
        }

        return $union;
    }

    private function isScalarNativeType(?Node $type): bool
    {
        if (!$type instanceof Node) {
            return true;
        }

        if ($type instanceof NullableType) {
            return $this->isScalarNativeType($type->type);
        }

        if ($type instanceof UnionType) {
            return array_all($type->types, fn (\PhpParser\Node\Identifier|\PhpParser\Node\IntersectionType|Node\Name $member): bool => $this->isScalarNativeType($member));
        }

        return $type instanceof Identifier
            && \in_array(strtolower($type->name), self::SCALAR_NATIVE_TYPES, true);
    }

    private function describe(FunctionLike $node): string
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            return $node->name->toString() . '()';
        }

        return 'a closure';
    }

    private function error(string $subject): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                '%s enumerates a closed set of scalar values in a docblock; declare it as a backed enum so the set is enforced at every call site and lives in one place.',
                $subject,
            ),
        )->identifier(self::IDENTIFIER)->build();
    }
}
