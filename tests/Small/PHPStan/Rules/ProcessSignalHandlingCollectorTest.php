<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ProcessSignalHandlingCollector;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use LTS\PHPQA\Tests\Support\CollectedProcessSignalEntriesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * What the collector records, made visible by a rule that reports each entry where it was
 * collected: every wait for a Process's child, and every try that catches its signal with the
 * spans inside its try block that do not run there.
 *
 * @internal
 *
 * @extends RuleTestCase<CollectedProcessSignalEntriesRule>
 */
#[CoversClass(ProcessSignalHandlingCollector::class)]
#[UsesClass(TestCodeDetector::class)]
#[Medium]
final class ProcessSignalHandlingCollectorTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/UnhandledProcessSignal/Calls.php';

    private const string GUARD_OF_ONE = 'guard of 1 call(s)';

    private const string CALL_RUN = 'call run';

    private const string CALL_WAIT = 'call wait';

    private const string CALL_MUST_RUN = 'call mustRun';

    private string $root = '/nonexistent-project-root';

    #[Test]
    public function itRecordsEachWaitAndEachTryThatCatchesTheSignal(): void
    {
        $this->analyse([self::FIXTURE], [
            [self::CALL_RUN, 18],
            [self::CALL_MUST_RUN, 19],
            [self::CALL_WAIT, 20],
            // The nullsafe call, and PHPStan's walk of it as a plain method call.
            [self::CALL_RUN, 21],
            [self::CALL_RUN, 21],
            ['guard of 2 call(s)', 26],
            [self::CALL_RUN, 27],
            [self::CALL_MUST_RUN, 28],
            [self::GUARD_OF_ONE, 33],
            [self::CALL_WAIT, 34],
            // start() in the try block; the calls in its catch and finally are not guarded.
            [self::GUARD_OF_ONE, 42],
            [self::CALL_RUN, 45],
            [self::CALL_WAIT, 47],
            // wait() in the try block; run() in the closure declared there is not guarded.
            [self::GUARD_OF_ONE, 53],
            [self::CALL_WAIT, 54],
            [self::CALL_RUN, 56],
            [self::CALL_RUN, 65],
        ]);
    }

    /** Only the waits are skipped in test code: a guard records nothing that could report. */
    #[Test]
    public function inTestCodeItRecordsNoWait(): void
    {
        $this->root = \dirname(__DIR__, 4);

        $this->analyse([self::FIXTURE], [
            ['guard of 2 call(s)', 26],
            [self::GUARD_OF_ONE, 33],
            [self::GUARD_OF_ONE, 42],
            [self::GUARD_OF_ONE, 53],
        ]);
    }

    protected function getRule(): Rule
    {
        return new CollectedProcessSignalEntriesRule();
    }

    protected function getCollectors(): array
    {
        return [new ProcessSignalHandlingCollector(new TestCodeDetector($this->root))];
    }
}
