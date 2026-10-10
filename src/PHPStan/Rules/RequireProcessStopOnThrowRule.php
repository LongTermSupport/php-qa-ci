<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A Symfony Process whose child is alive while first-party code runs must be
 * stopped in a finally. Process::start() returns with the child running, and a
 * callback given to run(), mustRun(), wait() or waitUntil() runs while it is.
 * If that code throws, nothing stops the child: Process holds its own wrapped
 * callback in a reference cycle, so its destructor, which would stop it, runs
 * only when the cycle collector happens to free it. Until then the child keeps
 * running, out of the caller's reach.
 *
 * A call is clean when it sits in the try or a catch block of a try statement
 * whose finally calls stop() on the same receiver expression, or when, in a
 * PHPUnit test case, the receiver is a `$this->` property that the tearDown()
 * or an #[After] method PHPUnit runs after the test stops. PHPStan nodes carry
 * no parent links, so the calls (typed through the scope, never by name), the
 * stopping try statements and the stopping test cases are gathered by three
 * collectors and matched here.
 *
 * Not reported: run(), mustRun() and wait() with no callback, because no
 * first-party code runs while the child is alive and Symfony stops the child
 * itself before throwing its timeout exception.
 *
 * See: docs/phpstan-rules/require-process-stop-on-throw.md
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class RequireProcessStopOnThrowRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.processStopOnThrow';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $guards    = $node->get(ProcessStoppingFinallyCollector::class);
        $testCases = [];
        foreach ($node->get(ProcessStoppingTearDownCollector::class) as $collected) {
            foreach ($collected as [$class, $declaresTearDown, $callsParent, $tearDownStops, $afterStops]) {
                $testCases[$class] = [$declaresTearDown, $callsParent, $tearDownStops, $afterStops];
            }
        }

        $errors = [];
        foreach ($node->get(ProcessLiveCodeCallCollector::class) as $file => $calls) {
            // PHPStan also walks a nullsafe call as a plain method call at the same position.
            $seen = [];
            foreach ($calls as [$method, $receiver, $line, $position, $lineage]) {
                if (isset($seen[$position])
                    || $this->isGuarded($receiver, $position, ...$guards[$file] ?? [])
                    || $this->isStoppedAfterEachTest($receiver, $testCases, ...$lineage)) {
                    continue;
                }

                $seen[$position] = true;

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    'Process::%1$s() lets first-party code run while the child of %2$s is alive, and is not inside a try '
                    . 'whose finally stops %2$s: if that code throws, the child is left running.',
                    $method,
                    $receiver,
                ))->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }

    /**
     * @param array{int, int, list<array{int, int}>, list<string>} ...$guards
     */
    private function isGuarded(string $receiver, int $position, array ...$guards): bool
    {
        foreach ($guards as [$start, $end, $excluded, $stopped]) {
            if ($position < $start || $position >= $end || !\in_array($receiver, $stopped, true)) {
                continue;
            }

            if (!$this->isInsideAny($position, ...$excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHPUnit runs tearDown() and every #[After] method after a test that throws.
     * Only the nearest tearDown() runs, and an ancestor's only through
     * parent::tearDown(); every #[After] method in the hierarchy runs. A class
     * in the lineage that was not analysed is passed over.
     *
     * @param array<string, array{bool, bool, list<string>, list<string>}> $testCases
     * @param string                                                       ...$lineage the call's class, then its ancestors
     */
    private function isStoppedAfterEachTest(string $receiver, array $testCases, string ...$lineage): bool
    {
        if (!str_starts_with($receiver, '$this->')) {
            return false;
        }

        $tearDownRuns = true;
        foreach ($lineage as $class) {
            if (!isset($testCases[$class])) {
                continue;
            }

            [$declaresTearDown, $callsParent, $tearDownStops, $afterStops] = $testCases[$class];
            if (\in_array($receiver, $afterStops, true)) {
                return true;
            }

            if ($tearDownRuns && $declaresTearDown) {
                if (\in_array($receiver, $tearDownStops, true)) {
                    return true;
                }

                $tearDownRuns = $callsParent;
            }
        }

        return false;
    }

    /**
     * @param array{int, int} ...$spans
     */
    private function isInsideAny(int $position, array ...$spans): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($position >= $start && $position <= $end) {
                return true;
            }
        }

        return false;
    }
}
