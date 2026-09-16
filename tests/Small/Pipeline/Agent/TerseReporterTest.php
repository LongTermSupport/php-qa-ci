<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\TerseReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(TerseReporter::class)]
#[UsesClass(AgentStatusEnum::class)]
#[Small]
final class TerseReporterTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string REPORT = '/project/var/qa/phpstan-file-reports/src/Kernel.php.json';

    private const string REPORT_LINE = 'REPORT: ' . self::REPORT;

    private BufferedOutput $stdout;

    private TerseReporter $reporter;

    protected function setUp(): void
    {
        $this->stdout   = new BufferedOutput();
        $this->reporter = new TerseReporter($this->stdout);
    }

    #[Test]
    public function aCleanRunIsTwoLinesTheVerdictAndTheReport(): void
    {
        $this->reporter->result(self::PHPSTAN, AgentStatusEnum::Clean, 0, 1, self::REPORT);

        self::assertSame(
            [
                'PHPSTAN AGENT MODE: 0 errors in 1 file',
                self::REPORT_LINE,
            ],
            $this->lines(),
        );
    }

    #[Test]
    public function aRunWithFindingsAddsTheInstructionToReadAndFixTheReport(): void
    {
        $this->reporter->result(self::PHPSTAN, AgentStatusEnum::Errors, 14, 1, self::REPORT);
        $lines = $this->lines();

        self::assertCount(3, $lines, 'agent mode is never more than three lines');
        self::assertSame('PHPSTAN AGENT MODE: 14 errors in 1 file', $lines[0]);
        self::assertSame(self::REPORT_LINE, $lines[1]);
        self::assertStringContainsString('ACTION REQUIRED', $lines[2]);
        self::assertStringContainsString('read', $lines[2]);
        self::assertStringContainsString('fix', $lines[2]);
        self::assertStringContainsString('do not guess', strtolower($lines[2]), 'the summary carries no findings, so guessing from it is the failure mode');
    }

    #[Test]
    public function theFileCountIsPluralisedSoTheLineReadsAsEnglish(): void
    {
        $this->reporter->result(self::PHPSTAN, AgentStatusEnum::Errors, 30, 7, self::REPORT);

        self::assertSame('PHPSTAN AGENT MODE: 30 errors in 7 files', $this->lines()[0]);
    }

    #[Test]
    public function asingleErrorIsNotReportedAsOneErrors(): void
    {
        $this->reporter->result(self::PHPSTAN, AgentStatusEnum::Errors, 1, 1, self::REPORT);

        self::assertSame('PHPSTAN AGENT MODE: 1 error in 1 file', $this->lines()[0]);
    }

    #[Test]
    public function lockContentionSaysNothingRanSoAGreenIsNeverInferredFromIt(): void
    {
        $this->reporter->lockHeld(self::PHPSTAN, 'host box, pid 4321, tool "phpunit"');
        $lines = $this->lines();

        self::assertCount(2, $lines);
        self::assertStringContainsString('lock-held', $lines[0]);
        self::assertStringContainsString('host box, pid 4321, tool "phpunit"', $lines[0]);
        self::assertStringContainsString('No analysis ran', $lines[1]);
        self::assertStringContainsString('no report was written', $lines[1]);
    }

    #[Test]
    public function aCrashNamesTheReasonAndSaysTheResultIsNotAVerdictOnTheCode(): void
    {
        $this->reporter->crashed(self::PHPSTAN, 'PHPStan crashed (exit 255)', self::REPORT);
        $lines = $this->lines();

        self::assertCount(3, $lines);
        self::assertStringContainsString('crashed', $lines[0]);
        self::assertStringContainsString('PHPStan crashed (exit 255)', $lines[0]);
        self::assertSame(self::REPORT_LINE, $lines[1]);
        self::assertStringContainsString('ACTION REQUIRED', $lines[2]);
    }

    /** @return list<string> the non-empty lines written to stdout */
    private function lines(): array
    {
        return array_values(array_filter(
            explode("\n", trim($this->stdout->fetch())),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }
}
