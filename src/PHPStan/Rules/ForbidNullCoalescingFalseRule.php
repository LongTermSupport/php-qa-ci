<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans ?? false — null coalescing with false default.
 *
 * Using false as a default silently converts null to false, hiding missing data.
 * Use explicit null checks or validate data at the boundary instead.
 *
 * See: docs/phpstan-rules/forbid-null-coalescing-false.md for fix documentation.
 *
 * @implements Rule<Coalesce>
 */
final class ForbidNullCoalescingFalseRule implements Rule
{
    public function getNodeType(): string
    {
        return Coalesce::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->right instanceof ConstFetch) {
            return [];
        }

        if ($node->right->name->toLowerString() !== 'false') {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Avoid ?? false — this hides null/undefined errors with false. '
                . 'Use an explicit null check or validate data at the API boundary.',
            )->identifier('counselbook.nullCoalescingFalse')->build(),
        ];
    }
}
