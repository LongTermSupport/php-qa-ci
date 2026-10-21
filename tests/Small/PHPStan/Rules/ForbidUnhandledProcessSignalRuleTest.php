<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidUnhandledProcessSignalRule;
use LTS\PHPQA\PHPStan\Rules\ProcessSignalHandlingCollector;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The fixture sits under this project's tests/, so the analysed project's root is moved
 * elsewhere for it to be read as production code, and put back to see it read as test code.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidUnhandledProcessSignalRule>
 */
#[CoversClass(ForbidUnhandledProcessSignalRule::class)]
#[CoversClass(ProcessSignalHandlingCollector::class)]
#[UsesClass(TestCodeDetector::class)]
#[Medium]
final class ForbidUnhandledProcessSignalRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/UnhandledProcessSignal/Calls.php';

    private const string RUN = 'run';

    private const string WAIT = 'wait';

    /** The analysed project's root, as PHPStan's working directory would give it. */
    private string $root = '/nonexistent-project-root';

    #[Test]
    public function aWaitForTheChildOutsideATryThatCatchesItsSignalIsReported(): void
    {
        $this->analyse([self::FIXTURE], [
            [$this->message(self::RUN), 18],
            [$this->message('mustRun'), 19],
            [$this->message(self::WAIT), 20],
            [$this->message(self::RUN), 21],
            [$this->message(self::RUN), 45],
            [$this->message(self::WAIT), 47],
            [$this->message(self::RUN), 54],
            [$this->message(self::RUN), 63],
        ]);
    }

    #[Test]
    public function testCodeIsNotReported(): void
    {
        $this->root = \dirname(__DIR__, 4);

        $this->analyse([self::FIXTURE], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidUnhandledProcessSignalRule();
    }

    protected function getCollectors(): array
    {
        return [new ProcessSignalHandlingCollector(new TestCodeDetector($this->root))];
    }

    private function message(string $method): string
    {
        return \sprintf(
            'Process::%s() throws ProcessSignaledException when a signal kills the child, and is not inside a try '
            . 'that catches ProcessSignaledException: the crash surfaces as "The process has been signaled with '
            . 'signal N." with no name for the signal or the command, and aborts whatever called it.',
            $method,
        );
    }
}
