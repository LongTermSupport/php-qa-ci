<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ProcessLiveCodeCallCollector;
use LTS\PHPQA\PHPStan\Rules\ProcessStopCallFinder;
use LTS\PHPQA\PHPStan\Rules\ProcessStoppingFinallyCollector;
use LTS\PHPQA\PHPStan\Rules\ProcessStoppingTearDownCollector;
use LTS\PHPQA\PHPStan\Rules\RequireProcessStopOnThrowRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\InputStream;

/**
 * @internal
 *
 * @extends RuleTestCase<RequireProcessStopOnThrowRule>
 */
#[CoversClass(RequireProcessStopOnThrowRule::class)]
#[CoversClass(ProcessLiveCodeCallCollector::class)]
#[CoversClass(ProcessStoppingFinallyCollector::class)]
#[CoversClass(ProcessStoppingTearDownCollector::class)]
#[CoversClass(ProcessStopCallFinder::class)]
#[Medium]
final class RequireProcessStopOnThrowRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/ProcessStopOnThrow';

    private const string LOCAL = '$process';

    private const string CHILD = '$this->child';

    private const string RUN = 'run';

    private const string START = 'start';

    private const string WAIT = 'wait';

    #[Test]
    public function everyCallThatRunsCodeWhileTheChildLivesWithoutAStoppingFinallyIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message(self::RUN, self::LOCAL), 26],
                [$this->message(self::RUN, self::LOCAL), 37],
                [$this->message('mustRun', self::LOCAL), 48],
                [$this->message(self::START, self::LOCAL), 56],
                [$this->message(self::RUN, self::LOCAL), 66],
                [$this->message(self::START, self::LOCAL), 79],
                [$this->message(self::START, self::LOCAL), 94],
                [$this->message('wait', '$this->process'), 103],
                [$this->message('waitUntil', self::LOCAL), 110],
                [$this->message(self::RUN, self::LOCAL), 115],
                [$this->message(self::START, self::LOCAL), 122],
                [$this->message(self::START, self::LOCAL), 129],
                [$this->message(self::START, self::LOCAL), 139],
                [$this->message(self::START, self::LOCAL), 155],
            ],
        );
    }

    #[Test]
    public function aStoppingFinallyNoCallbackOrANonProcessReceiverIsClean(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function onlyACallbackFreeStartImmediatelyBeforeAStoppingTryIsClean(): void
    {
        $this->analyse(
            [self::FIXTURES . '/StartBeforeTry.php', self::FIXTURES . '/start-before-try-script.php'],
            [
                [$this->message(self::START, self::LOCAL), 58],
                [$this->message(self::START, self::LOCAL), 70],
                [$this->message(self::START, self::LOCAL), 81],
                [$this->message(self::START, self::LOCAL), 91],
                [$this->message(self::START, self::LOCAL), 109],
            ],
        );
    }

    #[Test]
    public function aProcessWhoseInputMayBeATraversableIsReportedWithOrWithoutACallback(): void
    {
        $this->analyse(
            [self::FIXTURES . '/IteratorInput.php'],
            [
                [$this->message(self::RUN, self::LOCAL), 24],
                [$this->message('mustRun', self::LOCAL), 30],
                [$this->message(self::START, self::LOCAL), 36],
                [$this->message(self::WAIT, self::LOCAL), 37],
                [$this->message(self::WAIT, self::LOCAL), 44],
                [$this->message(self::START, self::LOCAL), 51],
                [$this->message(self::RUN, self::LOCAL), 62],
                [$this->message(self::RUN, self::LOCAL), 68],
                [$this->inputMessage('setInput', '$this->process', 'Generator<int, string, mixed, mixed>'), 74],
                [$this->inputMessage('__construct', null, 'Generator<int, string, mixed, mixed>'), 79],
            ],
        );
    }

    #[Test]
    public function aLoopVariableStartedInTheTryIsGuardedOnlyByAStopThatLoopsToo(): void
    {
        $this->analyse(
            [self::FIXTURES . '/LoopStart.php'],
            [
                [$this->message(self::START, self::LOCAL), 24],
                [$this->message(self::START, self::LOCAL), 38],
                [$this->message(self::START, '$holder->process'), 50],
                [$this->message(self::START, self::LOCAL), 62],
                [$this->message(self::START, self::LOCAL), 77],
                [$this->message(self::START, self::LOCAL), 89],
                [$this->message(self::START, self::LOCAL), 103],
            ],
        );
    }

    #[Test]
    public function aTestCasePropertyNoTearDownOrAfterMethodThatRunsStopsIsReported(): void
    {
        $this->analyse(
            [
                self::FIXTURES . '/AbstractStoppingTestCase.php',
                self::FIXTURES . '/OverridesTheStop.php',
                self::FIXTURES . '/TearDownStopsAnother.php',
                self::FIXTURES . '/NotATestCase.php',
                self::FIXTURES . '/StopsTheChildAfterEachTest.php',
                self::FIXTURES . '/OverridesTheTraitTearDown.php',
            ],
            [
                [$this->message(self::START, self::CHILD), 26],
                [$this->message(self::START, self::CHILD), 28],
                [$this->message(self::START, self::LOCAL), 35],
                [$this->inputMessage('setInput', self::CHILD, InputStream::class), 42],
                [$this->message(self::START, self::CHILD), 22],
                [$this->message(self::START, self::CHILD), 30],
            ],
        );
    }

    #[Test]
    public function aTestCasePropertyTheRunningTearDownOrAnAfterMethodStopsIsClean(): void
    {
        $this->analyse(
            [
                self::FIXTURES . '/AbstractStoppingTestCase.php',
                self::FIXTURES . '/TearDownGuarded.php',
                self::FIXTURES . '/InheritsTheStop.php',
                self::FIXTURES . '/ChainsToTheStop.php',
                // UnanalysedMiddle.php, between it and the base, is deliberately left out.
                self::FIXTURES . '/InheritsThroughAnUnanalysedBase.php',
                self::FIXTURES . '/TearDownThroughAHelper.php',
                self::FIXTURES . '/StopsTheChildAfterEachTest.php',
                self::FIXTURES . '/UsesTheStoppingTrait.php',
            ],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new RequireProcessStopOnThrowRule();
    }

    protected function getCollectors(): array
    {
        return [
            new ProcessLiveCodeCallCollector(),
            new ProcessStoppingFinallyCollector(),
            new ProcessStoppingTearDownCollector(),
        ];
    }

    private function message(string $method, string $receiver): string
    {
        return \sprintf(
            'Process::%1$s() lets first-party code run while the child of %2$s is alive, and is not inside a try '
            . 'whose finally stops %2$s: if that code throws, the child is left running.',
            $method,
            $receiver,
        );
    }

    private function inputMessage(string $method, ?string $receiver, string $type): string
    {
        return \sprintf(
            'Process::%1$s() gives %2$s an input of type %3$s, which Symfony iterates while the child is alive, '
            . 'and %2$s is not started or run in this function, where a finally that stops it could be seen: '
            . 'if iterating the input throws, the child is left running.',
            $method,
            $receiver ?? 'a Process held in no variable',
            $type,
        );
    }
}
