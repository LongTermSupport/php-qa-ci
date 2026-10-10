<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use JsonException;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\KillJudgementDto;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\VacuousKillDto;
use LTS\PHPQA\Pipeline\Lane\Infection\VacuousKillDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Infection scores a mutant as killed whenever the test process exits non-zero,
 * so a suite that cannot start under Infection's wrapper "kills" every mutant
 * and the MSI reads 100% while nothing was tested. The detector reads
 * Infection's JSON log and names each killed mutant whose test output shows
 * that no test ran, against the number of killed mutants it judged: only when
 * no test ran for any of them did the suite never start.
 *
 * @internal
 */
#[CoversClass(VacuousKillDetector::class)]
#[CoversClass(KillJudgementDto::class)]
#[UsesClass(VacuousKillDto::class)]
#[Small]
final class VacuousKillDetectorTest extends TestCase
{
    private const string BANNER = 'PHPUnit 13.4.1 by Sebastian Bergmann and contributors.';

    private const string RUNTIME = "Runtime:       PHP 8.5.11\nConfiguration: /p/var/qa/infection/tmp/phpunitConfiguration.abc.infection.xml";

    private const string SOURCE = '/p/src/Foo.php';

    private const string PLUS = 'Plus';

    // The output issue #122 recorded for every one of 198 "killed" mutants.
    private const string EXTENSION_FAILED = self::BANNER . "\n\n" . self::RUNTIME . "\n\nThere was 1 PHPUnit test runner warning:\n\n1) Bootstrapping of extension X failed: sys_get_temp_dir() was resolved as /tmp before the extension ran\n\nNo tests executed!";

    private const string FAILED_TEST = self::BANNER . "\n\n" . self::RUNTIME . "\n\nF\n\nTime: 00:00.010, Memory: 10.00 MB\n\nThere was 1 failure:\n\n1) FooTest::bar\nFailed asserting that 59 is identical to 60.\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.";

    // A mutant in code the test bootstrap runs: PHPUnit stops before the suite.
    private const string BOOTSTRAP_BROKEN = self::BANNER . "\n\nError in bootstrap script: LogicException:\nboom";

    #[Test]
    public function aKillWhoseOutputSaysNoTestsExecutedIsVacuous(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 56, 'DecrementInteger', self::EXTENSION_FAILED)]));

        self::assertCount(1, $found->vacuous);
        self::assertSame('/p/src/Foo.php:56 DecrementInteger', $found->vacuous[0]->mutant);
        self::assertSame(self::EXTENSION_FAILED, $found->vacuous[0]->output);
    }

    #[Test]
    public function aKillByAFailingTestIsReal(): void
    {
        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 56, 'DecrementInteger', self::FAILED_TEST)]))->vacuous);
    }

    #[Test]
    public function aKillByAnErroringTestIsReal(): void
    {
        $errored = self::BANNER . "\n\n" . self::RUNTIME . "\n\nE\n\nERRORS!\nTests: 3, Assertions: 2, Errors: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, 'TrueValue', $errored)]))->vacuous);
    }

    #[Test]
    public function aKillWithPassingTestsAndIssuesIsReal(): void
    {
        $issues = self::BANNER . "\n\n" . self::RUNTIME . "\n\n.\n\nOK, but there were issues!\nTests: 1, Assertions: 1, PHPUnit Warnings: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, 'TrueValue', $issues)]))->vacuous);
    }

    #[Test]
    public function aKillWherePhpunitStoppedBeforeRunningTheSuiteIsVacuous(): void
    {
        $stopped = self::BANNER . "\n\nCannot open bootstrap script \"/p/tests/bootstrap.php\"";

        $found = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 9, self::PLUS, $stopped)]));

        self::assertCount(1, $found->vacuous);
        self::assertSame('/p/src/Foo.php:9 Plus', $found->vacuous[0]->mutant);
    }

    #[Test]
    public function aKillWherePhpunitStartedTheSuiteButPrintedNoSummaryIsNotJudged(): void
    {
        // The mutant made the code under test call exit(): the suite started, a test ran into it.
        $exited = self::BANNER . "\n\n" . self::RUNTIME . "\n\n";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, 'Exit_', $exited)]))->vacuous);
    }

    #[Test]
    public function outputFromAnotherTestFrameworkIsNotJudged(): void
    {
        $codeception = "Codeception PHP Testing Framework v5.1.2\n\nUnit Tests (1)\n✖ FooTest: bar\n\nFAILURES!";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, self::PLUS, $codeception)]))->vacuous);
    }

    #[Test]
    public function colourCodesDoNotHideTheMarker(): void
    {
        $coloured = self::BANNER . "\n\n" . self::RUNTIME . "\n\n\e[30;43mNo tests executed!\e[0m";

        self::assertCount(1, new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, self::PLUS, $coloured)]))->vacuous);
    }

    #[Test]
    public function aTestNamedAfterTheMarkerDoesNotTripIt(): void
    {
        $named = self::BANNER . "\n\n" . self::RUNTIME . "\n\nThere was 1 failure:\n\n1) FooTest::it reports No tests executed! as a warning\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, self::PLUS, $named)]))->vacuous);
    }

    #[Test]
    public function onlyKilledMutantsAreJudged(): void
    {
        $log = $this->log(
            killed: [$this->mutant(self::SOURCE, 1, self::PLUS, self::FAILED_TEST), $this->mutant('/p/src/Bar.php', 2, 'Minus', self::EXTENSION_FAILED)],
            escaped: [$this->mutant('/p/src/Baz.php', 3, self::PLUS, self::EXTENSION_FAILED)],
            stats: $this->stats(90.0, 100.0),
        );

        $found = new VacuousKillDetector()->find($log);

        self::assertSame(2, $found->judged, 'the escaped mutant is not counted');
        self::assertSame(['/p/src/Bar.php:2 Minus'], array_map(static fn (VacuousKillDto $kill): string => $kill->mutant, $found->vacuous));
    }

    #[Test]
    public function everyVacuousKillIsFound(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [
            $this->mutant(self::SOURCE, 1, self::PLUS, self::EXTENSION_FAILED),
            $this->mutant(self::SOURCE, 2, self::PLUS, self::EXTENSION_FAILED),
        ]));

        self::assertSame(['/p/src/Foo.php:1 Plus', '/p/src/Foo.php:2 Plus'], array_map(static fn (VacuousKillDto $kill): string => $kill->mutant, $found->vacuous));
    }

    /** The #122 case: the suite cannot start under Infection, so no killed mutant ran a test. */
    #[Test]
    public function whenNoTestRanForAnyKilledMutantTheSuiteNeverStarted(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [
            $this->mutant(self::SOURCE, 1, self::PLUS, self::EXTENSION_FAILED),
            $this->mutant(self::SOURCE, 2, self::PLUS, self::BOOTSTRAP_BROKEN),
            $this->mutant(self::SOURCE, 3, self::PLUS, self::EXTENSION_FAILED),
        ]));

        self::assertSame(3, $found->judged);
        self::assertCount(3, $found->vacuous);
        self::assertTrue($found->suiteNeverStarted());
    }

    /**
     * One real kill proves the suite starts under Infection, so a mutant that
     * stopped it before any test ran broke the bootstrap itself.
     */
    #[Test]
    public function oneKillMadeByATestShowsTheSuiteStarts(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [
            $this->mutant(self::SOURCE, 1, self::PLUS, self::BOOTSTRAP_BROKEN),
            $this->mutant(self::SOURCE, 2, self::PLUS, self::FAILED_TEST),
            $this->mutant(self::SOURCE, 3, self::PLUS, self::BOOTSTRAP_BROKEN),
        ], stats: $this->stats(90.0, 100.0)));

        self::assertSame(3, $found->judged);
        self::assertSame(['/p/src/Foo.php:1 Plus', '/p/src/Foo.php:3 Plus'], array_map(static fn (VacuousKillDto $kill): string => $kill->mutant, $found->vacuous));
        self::assertFalse($found->suiteNeverStarted());
    }

    /**
     * Some kills vacuous and some not: the verdict may rest on the vacuous ones,
     * so the counts Infection scores from are read: detected is every kill,
     * error and timeout (9), out of the tested mutants (10) and the covered ones (9).
     */
    #[Test]
    public function aMixedJudgementCarriesTheCountsInfectionScoresFrom(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: $this->mixedKills(), stats: $this->stats(90.0, 100.0)));

        self::assertSame(9, $found->detected);
        self::assertSame(10, $found->tested);
        self::assertSame(9, $found->testedCovered);
        self::assertSame(80.0, $found->msiWithoutVacuous());
    }

    /** Run with --timeouts-as-escaped, Infection counts a timeout as not detected, and its MSI says so. */
    #[Test]
    public function aTimeoutIsLeftOutOfTheDetectedWhenInfectionLeftItOut(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: $this->mixedKills(), stats: $this->stats(80.0, 88.89)));

        self::assertSame(8, $found->detected);
    }

    /** Counts that give neither Infection's MSI nor its MSI with timeouts escaped are not counts to score from. */
    #[Test]
    public function statsThatDoNotAddUpToTheMsiAreRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('the stats in the Infection JSON log do not add up to its MSI of 55%');

        new VacuousKillDetector()->find($this->log(killed: $this->mixedKills(), stats: $this->stats(55.0, 55.0)));
    }

    #[Test]
    public function aMixedJudgementWithoutTheCountsIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('the Infection JSON log has no "stats" to work out the scores without the kills no test ran for');

        new VacuousKillDetector()->find($this->log(killed: $this->mixedKills()));
    }

    /** With every kill vacuous, or none, no score is worked out, so the stats are not needed. */
    #[Test]
    public function onlyAMixedJudgementNeedsTheCounts(): void
    {
        $none = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, self::PLUS, self::FAILED_TEST)]));
        $all  = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, self::PLUS, self::BOOTSTRAP_BROKEN)]));

        self::assertNull($none->detected);
        self::assertNull($all->detected);
    }

    #[Test]
    public function withNoKilledMutantThereIsNothingToJudge(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [], escaped: [$this->mutant(self::SOURCE, 3, self::PLUS, self::EXTENSION_FAILED)]));

        self::assertSame(0, $found->judged);
        self::assertSame([], $found->vacuous);
        self::assertFalse($found->suiteNeverStarted(), 'no kill, so no kill was vacuous');
    }

    #[Test]
    public function aKilledEntryWithNoMutatorIsNamedAsUnknown(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('the killed mutant ?:? ? in the Infection JSON log has no processOutput');

        new VacuousKillDetector()->find('{"killed":[{"diff":""}]}');
    }

    #[Test]
    public function aLogWithNoKilledListIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('no "killed" list');

        new VacuousKillDetector()->find('{"stats":{}}');
    }

    #[Test]
    public function aKilledMutantWithoutItsProcessOutputIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('/p/src/Foo.php:4 Plus');
        $this->expectExceptionMessageIsOrContains('processOutput');

        new VacuousKillDetector()->find(\Safe\json_encode(['killed' => [['mutator' => ['mutatorName' => self::PLUS, 'originalFilePath' => self::SOURCE, 'originalStartLine' => 4]]]]));
    }

    #[Test]
    public function aKilledEntryThatIsNotAMutantIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('processOutput');

        new VacuousKillDetector()->find('{"killed":["x"]}');
    }

    #[Test]
    public function aLogThatIsNotJsonIsRefused(): void
    {
        $this->expectException(JsonException::class);

        new VacuousKillDetector()->find('not json');
    }

    /**
     * @param list<array<string, mixed>> $killed
     * @param list<array<string, mixed>> $escaped
     * @param array<string, int|float>   $stats   the log's stats; killedCount alone when not given
     */
    private function log(array $killed, array $escaped = [], array $stats = []): string
    {
        return \Safe\json_encode(['stats' => [] === $stats ? ['killedCount' => \count($killed)] : $stats, 'escaped' => $escaped, 'killed' => $killed, 'errored' => []]);
    }

    /**
     * Stats as Infection writes them: 12 mutants, 1 skipped and 1 ignored, so
     * 10 tested, 1 of them not covered; 6 killed by tests, 1 by static
     * analysis, 1 errored and 1 timed out.
     *
     * @return array<string, int|float>
     */
    private function stats(float $msi, float $coveredCodeMsi): array
    {
        return [
            'totalMutantsCount'           => 12,
            'killedCount'                 => 6,
            'killedByStaticAnalysisCount' => 1,
            'notCoveredCount'             => 1,
            'escapedCount'                => 1,
            'errorCount'                  => 1,
            'syntaxErrorCount'            => 0,
            'skippedCount'                => 1,
            'ignoredCount'                => 1,
            'timeOutCount'                => 1,
            'msi'                         => $msi,
            'mutationCodeCoverage'        => 90,
            'coveredCodeMsi'              => $coveredCodeMsi,
        ];
    }

    /** @return list<array<string, mixed>> one kill no test made beside one a test made */
    private function mixedKills(): array
    {
        return [
            $this->mutant(self::SOURCE, 1, self::PLUS, self::BOOTSTRAP_BROKEN),
            $this->mutant(self::SOURCE, 2, self::PLUS, self::FAILED_TEST),
        ];
    }

    /** @return array<string, mixed> one entry as Infection's JsonReporter writes it */
    private function mutant(string $file, int $line, string $mutator, string $output): array
    {
        return [
            'mutator'       => ['mutatorName' => $mutator, 'originalSourceCode' => '', 'mutatedSourceCode' => '', 'originalFilePath' => $file, 'originalStartLine' => $line],
            'diff'          => '',
            'processOutput' => $output,
        ];
    }
}
