<?php

/**
 * EXAMPLE RULE - NOT FOR DIRECT USE
 * ==================================
 * This is a reference example for the phpstan-rule-creator agent.
 * Copy and adapt for your project's specific needs.
 *
 * Pattern: Empty catch blocks
 * Detects: catch blocks with no statements (or only comments)
 * Why: Empty catch blocks swallow exceptions silently. The code appears to work
 *      but errors are invisible. They originate as temporary scaffolding but
 *      become permanent because "the code appears to work".
 */

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules\Examples;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects empty catch blocks that silently swallow exceptions.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * Empty catch blocks prevent proper error propagation. When an exception is
 * caught and nothing happens, the error is invisible. No logging, no
 * re-throwing, no alternative handling. The system continues in a potentially
 * invalid state.
 *
 * THE SOLUTION:
 * =============
 * Every catch block must do something meaningful:
 *
 * WRONG:
 *   try { riskyOperation(); } catch (\Exception $e) { }
 *
 * RIGHT:
 *   try { riskyOperation(); } catch (\Exception $e) {
 *       $this->logger->error('Operation failed', ['exception' => $e]);
 *       throw $e; // or handle appropriately
 *   }
 *
 * @implements Rule<Catch_>
 */
final class ExampleEmptyCatchRule implements Rule
{
    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /**
     * @param Catch_ $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // A catch block with no statements is empty
        if ([] !== $node->stmts) {
            return [];
        }

        $types = [];
        foreach ($node->types as $type) {
            $types[] = $type->toString();
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Empty catch block for %s silently swallows exceptions. ' .
                    'At minimum, log the exception. If intentionally ignoring, ' .
                    'add a comment explaining why and a log statement.',
                    implode('|', $types)
                )
            )->identifier('emptyCatch.silentSwallow')->build(),
        ];
    }
}
