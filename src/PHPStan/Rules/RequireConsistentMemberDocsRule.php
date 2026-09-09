<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class's declarations of one kind are documented all or none.
 *
 * Two kinds are checked separately: constants (class constants and enum
 * cases) and properties (declared and constructor-promoted). Within a kind,
 * either every member carries a docblock with free text, or none does. A
 * docblock holding only tags (@var, @param, @see, ...) is type metadata and
 * does not count as documentation.
 *
 * Methods are not checked: a method's docblock explains a body the reader can
 * read, whereas a declaration has only its name and type, so a comment there is
 * the only place meaning beyond the name can live, and that is where mixed
 * coverage misleads.
 *
 * The usual fix is to delete comments that restate the name, not to add more.
 * See: docs/phpstan-rules/require-consistent-member-docs.md
 *
 * @implements Rule<InClassNode>
 */
final readonly class RequireConsistentMemberDocsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.consistentMemberDocs';

    private const string KIND_CONSTANTS = 'constants';

    private const string KIND_PROPERTIES = 'properties';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $constants  = [];
        $properties = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof ClassConst || $stmt instanceof EnumCase) {
                $constants[] = $stmt;
            } elseif ($stmt instanceof Property) {
                $properties[] = $stmt;
            } elseif ($stmt instanceof ClassMethod && '__construct' === $stmt->name->toLowerString()) {
                foreach ($stmt->params as $param) {
                    if (0 !== $param->flags) {
                        $properties[] = $param;
                    }
                }
            }
        }

        $errors = [];
        foreach ([self::KIND_CONSTANTS => $constants, self::KIND_PROPERTIES => $properties] as $kind => $members) {
            $error = $this->check($kind, $members);
            if ($error instanceof \PHPStan\Rules\IdentifierRuleError) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @param list<ClassConst|EnumCase|Property|Param> $members */
    private function check(string $kind, array $members): ?\PHPStan\Rules\IdentifierRuleError
    {
        $total = \count($members);
        if ($total < 2) {
            return null;
        }

        $undocumented = array_values(array_filter($members, static fn (Node $member): bool => !self::hasFreeText($member->getDocComment())));
        $documented   = $total - \count($undocumented);
        if (0 === $documented || $documented === $total) {
            return null;
        }

        return RuleErrorBuilder::message(\sprintf(
            '%d of %d %s are documented; document all of them or none (delete comments that restate the name). Undocumented: %s.',
            $documented,
            $total,
            $kind,
            implode(', ', array_map($this->name(...), $undocumented)),
        ))->line($undocumented[0]->getStartLine())->identifier(self::IDENTIFIER)->build();
    }

    private static function hasFreeText(?Doc $doc): bool
    {
        if (!$doc instanceof Doc) {
            return false;
        }

        foreach (explode("\n", $doc->getText()) as $line) {
            $line = trim(trim($line), '*/ ');
            if ('' !== $line && !str_starts_with($line, '@')) {
                return true;
            }
        }

        return false;
    }

    private function name(ClassConst|EnumCase|Property|Param $member): string
    {
        if ($member instanceof ClassConst) {
            return implode(', ', array_map(static fn (Node\Const_ $const): string => $const->name->toString(), $member->consts));
        }

        if ($member instanceof EnumCase) {
            return $member->name->toString();
        }

        if ($member instanceof Property) {
            return implode(', ', array_map(static fn (Node\PropertyItem $item): string => '$' . $item->name->toString(), $member->props));
        }

        return $member->var instanceof Node\Expr\Variable && \is_string($member->var->name) ? '$' . $member->var->name : '$?';
    }
}
