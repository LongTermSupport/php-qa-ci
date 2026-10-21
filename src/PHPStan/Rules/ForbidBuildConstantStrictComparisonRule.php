<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `PHP_ZTS` or `PHP_DEBUG` compared with `===` / `!==`, or as the subject of a `match`.
 * Their type depends on the PHP version (a bool on PHP 8.5, an int on older PHP, and PHPStan
 * types them 0|1 everywhere), so a strict comparison is always false on one of them and PHPStan
 * cannot say so. `(bool) \PHP_ZTS` is right on every version.
 *
 * See: docs/phpstan-rules/forbid-build-constant-strict-comparison.md
 *
 * @implements Rule<Expr>
 */
final readonly class ForbidBuildConstantStrictComparisonRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.buildConstantStrictComparison';

    private const array CONSTANTS = ['PHP_ZTS', 'PHP_DEBUG'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $operands = match (true) {
            $node instanceof Identical, $node instanceof NotIdentical => [$node->left, $node->right],
            $node instanceof Match_                                   => [$node->cond],
            default                                                   => [],
        };

        foreach ($operands as $operand) {
            if (!$operand instanceof ConstFetch) {
                continue;
            }

            $constant = $operand->name->toString();
            if (\in_array($constant, self::CONSTANTS, true)) {
                return [
                    RuleErrorBuilder::message(\sprintf(
                        '%s is compared strictly, but it is a bool on PHP 8.5 and an int on older PHP, so the comparison is always false on one of them. Write (bool) \%s.',
                        $constant,
                        $constant,
                    ))->identifier(self::IDENTIFIER)->build(),
                ];
            }
        }

        return [];
    }
}
