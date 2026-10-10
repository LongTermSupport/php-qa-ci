<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use JsonException;
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
 * that no test ran.
 *
 * @internal
 */
#[CoversClass(VacuousKillDetector::class)]
#[UsesClass(VacuousKillDto::class)]
#[Small]
final class VacuousKillDetectorTest extends TestCase
{
    private const string BANNER = 'PHPUnit 13.4.1 by Sebastian Bergmann and contributors.';

    private const string RUNTIME = "Runtime:       PHP 8.5.11\nConfiguration: /p/var/qa/infection/tmp/phpunitConfiguration.abc.infection.xml";

    private const string SOURCE = '/p/src/Foo.php';

    /** The output issue #122 recorded for every one of 198 "killed" mutants. */
    private const string EXTENSION_FAILED = self::BANNER . "\n\n" . self::RUNTIME . "\n\nThere was 1 PHPUnit test runner warning:\n\n1) Bootstrapping of extension X failed: sys_get_temp_dir() was resolved as /tmp before the extension ran\n\nNo tests executed!";

    private const string FAILED_TEST = self::BANNER . "\n\n" . self::RUNTIME . "\n\nF\n\nTime: 00:00.010, Memory: 10.00 MB\n\nThere was 1 failure:\n\n1) FooTest::bar\nFailed asserting that 59 is identical to 60.\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.";

    #[Test]
    public function aKillWhoseOutputSaysNoTestsExecutedIsVacuous(): void
    {
        $found = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 56, 'DecrementInteger', self::EXTENSION_FAILED)]));

        self::assertCount(1, $found);
        self::assertSame('/p/src/Foo.php:56 DecrementInteger', $found[0]->mutant);
        self::assertSame(self::EXTENSION_FAILED, $found[0]->output);
    }

    #[Test]
    public function aKillByAFailingTestIsReal(): void
    {
        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 56, 'DecrementInteger', self::FAILED_TEST)])));
    }

    #[Test]
    public function aKillByAnErroringTestIsReal(): void
    {
        $errored = self::BANNER . "\n\n" . self::RUNTIME . "\n\nE\n\nERRORS!\nTests: 3, Assertions: 2, Errors: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, 'TrueValue', $errored)])));
    }

    #[Test]
    public function aKillWithPassingTestsAndIssuesIsReal(): void
    {
        $issues = self::BANNER . "\n\n" . self::RUNTIME . "\n\n.\n\nOK, but there were issues!\nTests: 1, Assertions: 1, PHPUnit Warnings: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 1, 'TrueValue', $issues)])));
    }

    #[Test]
    public function aKillWherePhpunitStoppedBeforeRunningTheSuiteIsVacuous(): void
    {
        $stopped = self::BANNER . "\n\nCannot open bootstrap script \"/p/tests/bootstrap.php\"";

        $found = new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 9, 'Plus', $stopped)]));

        self::assertCount(1, $found);
        self::assertSame('/p/src/Foo.php:9 Plus', $found[0]->mutant);
    }

    #[Test]
    public function aKillWherePhpunitStartedTheSuiteButPrintedNoSummaryIsNotJudged(): void
    {
        // The mutant made the code under test call exit(): the suite started, a test ran into it.
        $exited = self::BANNER . "\n\n" . self::RUNTIME . "\n\n";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, 'Exit_', $exited)])));
    }

    #[Test]
    public function outputFromAnotherTestFrameworkIsNotJudged(): void
    {
        $codeception = "Codeception PHP Testing Framework v5.1.2\n\nUnit Tests (1)\n✖ FooTest: bar\n\nFAILURES!";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, 'Plus', $codeception)])));
    }

    #[Test]
    public function colourCodesDoNotHideTheMarker(): void
    {
        $coloured = self::BANNER . "\n\n" . self::RUNTIME . "\n\n\e[30;43mNo tests executed!\e[0m";

        self::assertCount(1, new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, 'Plus', $coloured)])));
    }

    #[Test]
    public function aTestNamedAfterTheMarkerDoesNotTripIt(): void
    {
        $named = self::BANNER . "\n\n" . self::RUNTIME . "\n\nThere was 1 failure:\n\n1) FooTest::it reports No tests executed! as a warning\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.";

        self::assertSame([], new VacuousKillDetector()->find($this->log(killed: [$this->mutant(self::SOURCE, 3, 'Plus', $named)])));
    }

    #[Test]
    public function onlyKilledMutantsAreJudged(): void
    {
        $log = $this->log(
            killed: [$this->mutant(self::SOURCE, 1, 'Plus', self::FAILED_TEST), $this->mutant('/p/src/Bar.php', 2, 'Minus', self::EXTENSION_FAILED)],
            escaped: [$this->mutant('/p/src/Baz.php', 3, 'Plus', self::EXTENSION_FAILED)],
        );

        $found = new VacuousKillDetector()->find($log);

        self::assertSame(['/p/src/Bar.php:2 Minus'], array_map(static fn (VacuousKillDto $kill): string => $kill->mutant, $found));
    }

    #[Test]
    public function aLogWithNoKilledListIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('no "killed" list');

        new VacuousKillDetector()->find('{"stats":{}}');
    }

    #[Test]
    public function aKilledMutantWithoutItsProcessOutputIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('/p/src/Foo.php:4 Plus');
        $this->expectExceptionMessage('processOutput');

        new VacuousKillDetector()->find(\Safe\json_encode(['killed' => [['mutator' => ['mutatorName' => 'Plus', 'originalFilePath' => self::SOURCE, 'originalStartLine' => 4]]]]));
    }

    #[Test]
    public function aKilledEntryThatIsNotAMutantIsRefused(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('processOutput');

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
     */
    private function log(array $killed, array $escaped = []): string
    {
        return \Safe\json_encode(['stats' => ['killedCount' => \count($killed)], 'escaped' => $escaped, 'killed' => $killed, 'errored' => []]);
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
