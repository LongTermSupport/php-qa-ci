<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ExitCodeTextCollector;
use LTS\PHPQA\Tests\Support\CollectedExitCodeTextEntriesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the collector records, made visible by a rule that reports each entry where it was
 * collected: every variable assigned an exit code, and every exit code or variable put into text,
 * unless PHPStan proves it is no code a shell gives a signal kill.
 *
 * @internal
 *
 * @extends RuleTestCase<CollectedExitCodeTextEntriesRule>
 */
#[CoversClass(ExitCodeTextCollector::class)]
#[Medium]
final class ExitCodeTextCollectorTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/HandFormattedExitCode/Reports.php';

    private const string TEXT = 'text PhpQaCiFixture\HandFormattedExitCode\Reports::handFormatted';

    #[Test]
    public function itRecordsEachCopyOfTheCodeAndEachPlaceTheCodeOrAVariableBecomesText(): void
    {
        $this->analyse([self::FIXTURE], [
            ['assigned PhpQaCiFixture\HandFormattedExitCode\Reports::handFormatted $copy', 16],
            [self::TEXT, 18],
            [self::TEXT, 21],
            [self::TEXT, 22],
            [self::TEXT, 23],
            [self::TEXT, 24],
            [self::TEXT, 25],
            [self::TEXT, 26],
            [self::TEXT, 27],
            [self::TEXT . ' $copy', 28],
            [self::TEXT, 29],
            ['text PhpQaCiFixture\HandFormattedExitCode\Reports::narrowedIntoTheRange', 38],
            // A variable of the same name, in a function that assigns it no exit code.
            ['text PhpQaCiFixture\HandFormattedExitCode\Reports::unrelated $copy', 78],
        ]);
    }

    protected function getRule(): Rule
    {
        return new CollectedExitCodeTextEntriesRule();
    }

    protected function getCollectors(): array
    {
        return [new ExitCodeTextCollector()];
    }
}
