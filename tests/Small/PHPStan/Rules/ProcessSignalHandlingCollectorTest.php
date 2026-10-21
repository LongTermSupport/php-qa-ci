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

    private const string NO_EXCLUSIONS = 'guard, 0 excluded';

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
            [self::NO_EXCLUSIONS, 26],
            [self::CALL_RUN, 27],
            [self::CALL_MUST_RUN, 28],
            [self::NO_EXCLUSIONS, 33],
            [self::CALL_WAIT, 34],
            [self::NO_EXCLUSIONS, 42],
            [self::CALL_RUN, 45],
            [self::CALL_WAIT, 47],
            ['guard, 1 excluded', 53],
            [self::CALL_RUN, 54],
            [self::CALL_RUN, 63],
        ]);
    }

    /** Only the waits are skipped in test code: a guard records nothing that could report. */
    #[Test]
    public function inTestCodeItRecordsNoWait(): void
    {
        $this->root = \dirname(__DIR__, 4);

        $this->analyse([self::FIXTURE], [
            [self::NO_EXCLUSIONS, 26],
            [self::NO_EXCLUSIONS, 33],
            [self::NO_EXCLUSIONS, 42],
            ['guard, 1 excluded', 53],
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
