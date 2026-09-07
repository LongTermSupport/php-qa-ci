<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Ternary;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans nested ternary expressions.
 *
 * Nested ternaries are hard to read and reason about. Extract conditions
 * to named variables instead.
 *
 * WRONG:
 *   $x = $a ? ($b ? $c : $d) : $e;
 *   $x = ($a ? $b : $c) ? $d : $e;
 *   $x = $a ? $b : ($c ? $d : $e);
 *
 * RIGHT:
 *   $inner = $b ? $c : $d;
 *   $x = $a ? $inner : $e;
 *
 * @implements Rule<Ternary>
 */
final class ForbidNestedTernaryRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.nestedTernary';

    public function getNodeType(): string
    {
        return Ternary::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $condIsNested  = $node->cond instanceof Ternary;
        $ifIsNested    = $node->if instanceof Ternary;
        $elseIsNested  = $node->else instanceof Ternary;

        if (!$condIsNested && !$ifIsNested && !$elseIsNested) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Nested ternary expressions are banned. Extract conditions to named variables for clarity.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
