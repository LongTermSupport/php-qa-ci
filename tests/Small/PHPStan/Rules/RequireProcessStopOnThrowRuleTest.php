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

    private const string RUN = 'run';

    private const string START = 'start';

    #[Test]
    public function everyCallThatRunsCodeWhileTheChildLivesWithoutAStoppingFinallyIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message(self::RUN, self::LOCAL), 22],
                [$this->message(self::RUN, self::LOCAL), 33],
                [$this->message('mustRun', self::LOCAL), 44],
                [$this->message(self::START, self::LOCAL), 52],
                [$this->message(self::RUN, self::LOCAL), 62],
                [$this->message(self::START, self::LOCAL), 75],
                [$this->message(self::START, self::LOCAL), 88],
                [$this->message('wait', '$this->process'), 97],
                [$this->message('waitUntil', self::LOCAL), 104],
                [$this->message(self::RUN, self::LOCAL), 109],
                [$this->message(self::START, self::LOCAL), 116],
            ],
        );
    }

    #[Test]
    public function aStoppingFinallyNoCallbackOrANonProcessReceiverIsClean(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function aTestCasePropertyNoTearDownOrAfterMethodThatRunsStopsIsReported(): void
    {
        $this->analyse(
            [
                self::FIXTURES . '/AbstractStoppingTestCase.php',
                self::FIXTURES . '/OverridesTheStop.php',
                self::FIXTURES . '/TearDownStopsAnother.php',
            ],
            [
                [$this->message(self::START, '$this->child'), 19],
                [$this->message(self::START, '$this->child'), 25],
                [$this->message(self::START, self::LOCAL), 32],
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
}
