<?php

/**
 * EXAMPLE RULE - NOT FOR DIRECT USE
 * ==================================
 * This is a reference example for the phpstan-rule-creator agent.
 * Copy and adapt for your project's specific needs.
 *
 * Pattern: Silent default via null coalesce to empty string
 * Detects: $value ?? '' and $value ?? []
 * Why: Silent defaults hide missing data, renamed fields, failed lookups.
 *      A renamed API field, a failed database lookup, a wrong property path
 *      all produce the same result: empty string. The real error is invisible.
 */

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules\Examples;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects null coalesce operators that silently default to empty values.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * Silent defaults mask missing data. When code uses `$value ?? ''`, a null
 * value (which might indicate a bug) is silently converted to an empty string.
 * The error becomes invisible and propagates through the system.
 *
 * THE SOLUTION:
 * =============
 * Use null coalesce with throw to fail fast on unexpected nulls:
 *
 * WRONG:
 *   $name = $data['name'] ?? '';
 *   $items = $result ?? [];
 *
 * RIGHT:
 *   $name = $data['name'] ?? throw new \RuntimeException('Missing name');
 *   if (!isset($result)) { throw new \RuntimeException('Missing result'); }
 *
 * @implements Rule<Coalesce>
 */
final class ExampleSilentDefaultRule implements Rule
{
    /** @var list<class-string<Node\Expr>> */
    private const array EMPTY_VALUE_TYPES = [
        String_::class,
        Array_::class,
    ];

    public function getNodeType(): string
    {
        return Coalesce::class;
    }

    /**
     * @param Coalesce $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $right = $node->right;

        // Check if right side is an empty string
        if ($right instanceof String_ && '' === $right->value) {
            return [
                RuleErrorBuilder::message(
                    'Null coalesce to empty string (?? \'\') hides missing data. ' .
                    'Use ?? throw new \\RuntimeException(\'Expected value missing\') ' .
                    'to fail fast on unexpected nulls.'
                )->identifier('silentDefault.emptyString')->build(),
            ];
        }

        // Check if right side is an empty array
        if ($right instanceof Array_ && [] === $right->items) {
            return [
                RuleErrorBuilder::message(
                    'Null coalesce to empty array (?? []) hides missing data. ' .
                    'Use ?? throw new \\RuntimeException(\'Expected array missing\') ' .
                    'to fail fast on unexpected nulls.'
                )->identifier('silentDefault.emptyArray')->build(),
            ];
        }

        return [];
    }
}
