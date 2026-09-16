<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent\Dto;

use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FileReportDto::class)]
#[UsesClass(AgentStatusEnum::class)]
#[UsesClass(FileErrorDto::class)]
#[Small]
final class FileReportDtoTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string PATH = 'src/Kernel.php';

    private const string LOG = '/project/var/qa/phpstan_logs/phpstan.json';

    #[Test]
    public function theSchemaKeysAreTheDocumentedOnesInTheDocumentedOrder(): void
    {
        self::assertSame(
            ['tool', 'path', 'status', 'run_at', 'exit_code', 'error_count', 'errors', 'log_path'],
            array_keys($this->clean()->jsonSerialize()),
        );
    }

    #[Test]
    public function aCleanReportCarriesZeroAndAnEmptyErrorListSoAStaleRedCannotSurviveTheFix(): void
    {
        $report = $this->clean();

        self::assertSame(0, $report->errorCount());
        $wire = $report->jsonSerialize();
        self::assertSame('clean', $wire['status']);
        self::assertSame(0, $wire['error_count']);
        self::assertSame([], $wire['errors']);
        self::assertSame(0, $wire['exit_code']);
    }

    #[Test]
    public function theErrorCountIsDerivedFromTheErrorsSoTheTwoCanNeverDisagree(): void
    {
        $report = FileReportDto::forErrors(
            self::PHPSTAN,
            self::PATH,
            1_764_000_000,
            [new FileErrorDto(12, 'a', 'x.y', null), new FileErrorDto(13, 'b', null, null)],
            self::LOG,
        );

        self::assertSame(2, $report->errorCount());
        self::assertSame(AgentStatusEnum::Errors, $report->status);
        self::assertSame(1, $report->jsonSerialize()['exit_code']);
        self::assertCount(2, $report->jsonSerialize()['errors']);
    }

    #[Test]
    public function anEmptyErrorListMakesTheReportCleanWithoutTheCallerSayingSo(): void
    {
        $report = FileReportDto::forErrors(self::PHPSTAN, self::PATH, 1_764_000_000, [], self::LOG);

        self::assertSame(AgentStatusEnum::Clean, $report->status);
        self::assertSame(0, $report->jsonSerialize()['exit_code']);
    }

    #[Test]
    public function theRunTimestampIsAnIso8601StringRatherThanAUnixInteger(): void
    {
        $report = FileReportDto::forErrors(self::PHPSTAN, self::PATH, 1_764_000_000, [], self::LOG);

        self::assertSame(gmdate('Y-m-d\TH:i:sP', 1_764_000_000), $report->jsonSerialize()['run_at']);
    }

    #[Test]
    public function aCrashedReportKeepsItsOwnStatusAndExitCode(): void
    {
        $report = FileReportDto::crashed(self::PHPSTAN, self::PATH, 1_764_000_000, 'PHPStan crashed (exit 255)', self::LOG);

        self::assertSame(AgentStatusEnum::Crashed, $report->status);
        $wire = $report->jsonSerialize();
        self::assertSame('crashed', $wire['status']);
        self::assertSame(3, $wire['exit_code']);
        self::assertSame(1, $wire['error_count']);
        self::assertSame('PHPStan crashed (exit 255)', $wire['errors'][0]->message);
    }

    #[Test]
    public function rewritingThePathKeepsTheStatusSoACrashNeverBecomesAnOrdinaryFinding(): void
    {
        $crashed = FileReportDto::crashed(self::PHPSTAN, '/abs/project/' . self::PATH, 1_764_000_000, 'boom', self::LOG);

        $moved = $crashed->withPath(self::PATH);

        self::assertSame(self::PATH, $moved->path);
        self::assertSame(AgentStatusEnum::Crashed, $moved->status);
        self::assertSame(3, $moved->jsonSerialize()['exit_code']);
        self::assertSame($crashed->errors, $moved->errors);
    }

    private function clean(): FileReportDto
    {
        return FileReportDto::forErrors(self::PHPSTAN, self::PATH, 1_764_000_000, [], self::LOG);
    }
}
