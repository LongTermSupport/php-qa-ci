<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A ProcessResultDto's exit code formatted into text by hand. A code from 129
 * to 192 is how a shell reports a signal kill, but a child also exits with
 * one itself: a wrapper relays a crash below it as 139. Only the result knows
 * which (its signal is set only when a signal killed the child), so text built
 * from the bare code either says nothing of a crash, or calls a relayed one a
 * kill: "killed by signal 11 (SIGSEGV)" about a process that was not killed,
 * or "rejected the change (exit 139)" about one that crashed.
 *
 * WRONG:
 *
 *   sprintf('PHPUnit crashed (exit %d)', $result->exitCode)
 *   'failed with ' . ChildExit::describe($result->exitCode)
 *
 * RIGHT:
 *
 *   sprintf('PHPUnit crashed (%s)', $result->describeExit())
 *   ToolResultDto::fromProcessResult($result, 'PHPUnit', 1, 2)
 *
 * A local variable a function assigns the code to is matched as the code. A
 * value PHPStan proves lies outside 129 to 192 is not reported: it cannot be
 * read as a signal's code.
 *
 * See: docs/phpstan-rules/forbid-hand-formatted-exit-code.md
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidHandFormattedExitCodeRule implements Rule
{
    /** The stable identifier every finding of this rule carries. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.handFormattedExitCode';

    /** What every finding says. */
    public const string MESSAGE = 'A ProcessResultDto exit code is formatted into text by hand: a code from 129 to 192 then says '
        . 'nothing of a crash, or calls a child that exited with it itself, relaying a crash below it, killed. Say how '
        . 'the child ended with $result->describeExit(), or ToolResultDto::fromProcessResult().';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($node->get(ExitCodeTextCollector::class) as $file => $collected) {
            $entries = array_merge(...$collected);
            // Function key + variable name => true, for every local assigned an exit code.
            $copies = [];
            foreach ($entries as [$kind, $key, $variable]) {
                if (ExitCodeTextCollector::ASSIGNED === $kind) {
                    $copies[$key . '$' . $variable] = true;
                }
            }

            foreach ($entries as [$kind, $key, $variable, $line]) {
                if (ExitCodeTextCollector::TEXT !== $kind || ('' !== $variable && !\array_key_exists($key . '$' . $variable, $copies))) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(self::MESSAGE)->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }
}
