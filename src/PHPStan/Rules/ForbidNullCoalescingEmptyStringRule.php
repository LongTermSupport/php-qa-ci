<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans ?? '' — null coalescing with empty string default.
 *
 * Empty string defaults silently convert null to '', hiding missing data.
 * Use explicit null checks or validate data at the boundary instead.
 *
 * Allowed: ?? 'meaningful-default' (non-empty string defaults are fine)
 * Allowed: ?? 0 (zero is a meaningful numeric default)
 *
 * See: docs/phpstan-rules/forbid-null-coalescing-empty-string.md for fix documentation.
 *
 * @implements Rule<Coalesce>
 */
final readonly class ForbidNullCoalescingEmptyStringRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.nullCoalescingEmptyString';

    public function getNodeType(): string
    {
        return Coalesce::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->right instanceof String_) {
            return [];
        }

        if ('' !== $node->right->value) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                "Avoid ?? '' — this hides null/undefined errors with an empty string. "
                . 'Use an explicit null check or validate data at the API boundary.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
