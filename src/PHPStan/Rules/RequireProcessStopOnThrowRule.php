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
 * Symfony iterates a Traversable input inside start(), run(), mustRun() and
 * wait(), so a call with no callback on a receiver the same function gives
 * such an input is reported as one with a callback is, and the input itself is
 * reported when that function starts or runs nothing on the receiver.
 *
 * Not reported: run(), mustRun() and wait() with no callback and no iterator
 * input, because no first-party code runs while the child is alive and
 * Symfony stops the child itself before throwing its timeout exception; and a
 * start() with neither that is the statement immediately before a try whose
 * finally stops its receiver, because nothing of the caller's runs before the
 * guard is entered.
 *
 * See: docs/phpstan-rules/require-process-stop-on-throw.md
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class RequireProcessStopOnThrowRule implements Rule
{
    /** The stable identifier every finding of this rule carries. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.processStopOnThrow';

    /** The calls that run first-party code only through a callback or an iterator input; start() runs the caller's after it, and waitUntil() always takes a callback. */
    private const array CALLBACK_ONLY = ['run', 'mustRun', 'wait'];

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $guards = [];
        foreach ($node->get(ProcessStoppingFinallyCollector::class) as $file => $collected) {
            $guards[$file] = array_merge(...$collected);
        }

        $testCases = [];
        foreach ($node->get(ProcessStoppingTearDownCollector::class) as $collected) {
            foreach ($collected as [$class, $runsParentTearDown, $tearDownStops, $afterStops]) {
                $testCases[$class] = [$runsParentTearDown, $tearDownStops, $afterStops];
            }
        }

        $errors = [];
        foreach ($node->get(ProcessLiveCodeCallCollector::class) as $file => $collected) {
            // Keyed by position: PHPStan also walks a nullsafe call as a plain method call there.
            $calls  = [];
            $inputs = [];
            // Enclosing function => receiver => true, for every call and for every iterator input.
            $called = [];
            $fed    = [];
            foreach ($collected as $entry) {
                [, $receiver, , $position, , , $function, $inputType] = $entry;
                if (null === $inputType) {
                    $calls[$position]             = $entry;
                    $called[$function][$receiver] = true;

                    continue;
                }

                // A new Process assigned to a variable is also seen alone, held in no variable.
                if (ProcessLiveCodeCallCollector::UNHELD !== $receiver || !isset($inputs[$position])) {
                    $inputs[$position]         = $entry;
                    $fed[$function][$receiver] = true;
                }
            }

            foreach ($calls as [$method, $receiver, $line, $position, $lineage, $givesACallback, $function]) {
                $runsNoCodeOfItsOwn = !$givesACallback && !isset($fed[$function][$receiver]);
                if (($runsNoCodeOfItsOwn && \in_array($method, self::CALLBACK_ONLY, true))
                    || $this->isGuarded($receiver, $position, ...$guards[$file] ?? [])
                    || ($runsNoCodeOfItsOwn && $this->startsImmediatelyBeforeAGuard($position, ...$guards[$file] ?? []))
                    || $this->isStoppedAfterEachTest($receiver, $testCases, ...$lineage)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    'Process::%1$s() lets first-party code run while the child of %2$s is alive, and is not inside a try '
                    . 'whose finally stops %2$s: if that code throws, the child is left running.',
                    $method,
                    $receiver,
                ))->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }

            foreach ($inputs as [$method, $receiver, $line, , $lineage, , $function, $inputType]) {
                if (isset($called[$function][$receiver]) || $this->isStoppedAfterEachTest($receiver, $testCases, ...$lineage)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    'Process::%1$s() gives %2$s an input of type %3$s, which Symfony iterates while the child is alive, '
                    . 'and %2$s is not started or run in this function, where a finally that stops it could be seen: '
                    . 'if iterating the input throws, the child is left running.',
                    $method,
                    $receiver,
                    $inputType,
                ))->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }

    /**
     * @param array{int, int, list<array{int, int}>, list<string>, int|null} ...$guards
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
     * A start() that runs no first-party code itself, and is the statement
     * immediately before a try whose finally stops its receiver, leaves nothing
     * of the caller's to run before the guard is entered. The collector records
     * the start() only when the finally stops that receiver.
     *
     * @param array{int, int, list<array{int, int}>, list<string>, int|null} ...$guards
     */
    private function startsImmediatelyBeforeAGuard(int $position, array ...$guards): bool
    {
        return array_any($guards, static fn (array $guard): bool => $position === $guard[4]);
    }

    /**
     * PHPUnit runs tearDown() and every #[After] method after a test that throws.
     * Only the nearest tearDown() runs, and an ancestor's only through
     * parent::tearDown(); every #[After] method in the hierarchy runs. A class
     * in the lineage that was not analysed is passed over.
     *
     * @param array<string, array{bool, list<string>, list<string>}> $testCases
     * @param string                                                 ...$lineage the call's class, then its ancestors
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

            [$runsParentTearDown, $tearDownStops, $afterStops] = $testCases[$class];
            if (\in_array($receiver, $afterStops, true)) {
                return true;
            }

            if ($tearDownRuns && \in_array($receiver, $tearDownStops, true)) {
                return true;
            }

            $tearDownRuns = $tearDownRuns && $runsParentTearDown;
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
