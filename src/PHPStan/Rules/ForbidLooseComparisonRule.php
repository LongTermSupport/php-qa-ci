<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans loose comparison operators == and !=.
 *
 * Loose comparisons perform type coercion, leading to surprising results:
 * - "0" == false  (true)
 * - "" == null    (true)
 * - "1" == true   (true)
 *
 * Strict comparisons (=== and !==) compare both value and type.
 *
 * WRONG:
 *   if ($status == 'active') { ... }
 *
 * RIGHT:
 *   if ($status === 'active') { ... }
 *
 * @implements Rule<BinaryOp>
 */
final readonly class ForbidLooseComparisonRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.looseComparison';

    public function getNodeType(): string
    {
        return BinaryOp::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Equal) {
            return [
                RuleErrorBuilder::message(
                    'Loose comparison (==) is banned. Use strict comparison (===) instead. '
                    . 'Loose comparisons cause type coercion bugs.',
                )->identifier(self::IDENTIFIER)->build(),
            ];
        }

        if ($node instanceof NotEqual) {
            return [
                RuleErrorBuilder::message(
                    'Loose comparison (!=) is banned. Use strict comparison (!==) instead. '
                    . 'Loose comparisons cause type coercion bugs.',
                )->identifier(self::IDENTIFIER)->build(),
            ];
        }

        return [];
    }
}
