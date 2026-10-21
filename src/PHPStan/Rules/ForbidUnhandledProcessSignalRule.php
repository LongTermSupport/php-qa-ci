<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A wait for the child of a Symfony Process that does not handle the child
 * being killed by a signal. run(), mustRun() and wait() throw
 * ProcessSignaledException when a signal the Process did not send itself
 * killed the child, so a segmentation fault, an out-of-memory kill or a stray
 * SIGTERM surfaces as "The process has been signaled with signal "11"." with
 * no word for the signal and no command, and aborts whatever called it: a QA
 * run abandons every lane after the one whose tool crashed, without naming it.
 *
 * WRONG:
 *
 *   $process->run();
 *
 * RIGHT:
 *
 *   try {
 *       $process->run();
 *   } catch (ProcessSignaledException $signaled) {
 *       // name the command and the signal; a SIGSEGV is a crash to halt on
 *   }
 *
 * Only a catch that names ProcessSignaledException counts: a catch of a
 * supertype cannot read the signal without testing for the subtype. Test code
 * is exempt, because there the exception fails the one test that ran the child,
 * and PHPUnit prints it against that test with the signal's number.
 *
 * See: docs/phpstan-rules/forbid-unhandled-process-signal.md
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidUnhandledProcessSignalRule implements Rule
{
    /** The stable identifier every finding of this rule carries. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unhandledProcessSignal';

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
        foreach ($node->get(ProcessSignalHandlingCollector::class) as $file => $entries) {
            $guards = array_filter($entries, static fn (array $entry): bool => ProcessSignalHandlingCollector::GUARD === $entry[0]);
            // Keyed by offset: PHPStan also walks a nullsafe call as a plain method call there.
            $calls = [];
            foreach ($entries as $entry) {
                if (ProcessSignalHandlingCollector::CALL === $entry[0]) {
                    $calls[$entry[3]] = $entry;
                }
            }

            foreach ($calls as [, $method, $line, $position]) {
                if ($this->isGuarded($position, ...$guards)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    'Process::%s() throws ProcessSignaledException when a signal kills the child, and is not inside a try '
                    . 'that catches ProcessSignaledException: the crash surfaces as "The process has been signaled with '
                    . 'signal N." with no name for the signal or the command, and aborts whatever called it.',
                    $method,
                ))->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }

    /**
     * @param array{string, string, int, int, int, list<array{int, int}>} ...$guards
     */
    private function isGuarded(int $position, array ...$guards): bool
    {
        foreach ($guards as [, , , $start, $end, $excluded]) {
            if ($position >= $start && $position < $end && !array_any($excluded, static fn (array $span): bool => $position >= $span[0] && $position <= $span[1])) {
                return true;
            }
        }

        return false;
    }
}
