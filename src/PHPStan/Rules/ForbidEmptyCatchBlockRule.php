<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans catch blocks with empty bodies.
 *
 * Empty catch blocks silently swallow exceptions, hiding errors.
 * At minimum, log the exception or rethrow it.
 * Comments alone are not sufficient — they are not AST statements.
 *
 * See: docs/phpstan-rules/forbid-empty-catch-block.md for fix documentation.
 *
 * @implements Rule<Catch_>
 */
final class ForbidEmptyCatchBlockRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.emptyCatchBlock';

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (\count($node->stmts) > 0) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Empty catch block detected — exceptions must be handled (log, rethrow, or return). '
                . 'A comment alone is not sufficient handling.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
