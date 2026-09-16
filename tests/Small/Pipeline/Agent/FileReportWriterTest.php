<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use FilesystemIterator;
use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto;
use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 */
#[CoversClass(FileReportWriter::class)]
#[UsesClass(AgentStatusEnum::class)]
#[UsesClass(FileErrorDto::class)]
#[UsesClass(FileReportDto::class)]
#[Small]
final class FileReportWriterTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string KERNEL = 'src/Kernel.php';

    private const string LOG = '/var/qa/phpstan_logs/phpstan.json';

    private const int RUN_AT = 1_764_000_000;

    private const string THING_TEST = 'tests/Unit/ThingTest.php';

    private TempDir $project;

    private FileReportWriter $writer;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-agent-reports');
        $this->writer  = new FileReportWriter($this->project->path . '/var/qa/phpstan-file-reports', $this->project->path);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theReportPathMirrorsTheSourcePathAndKeepsTheExtension(): void
    {
        self::assertSame(
            $this->project->path . '/var/qa/phpstan-file-reports/src/Kernel.php.json',
            $this->writer->reportPathFor(self::KERNEL),
        );
    }

    #[Test]
    public function twoSourceFilesThatDifferOnlyByExtensionGetDifferentReports(): void
    {
        self::assertNotSame(
            $this->writer->reportPathFor('src/Thing.php'),
            $this->writer->reportPathFor('src/Thing.phtml'),
        );
    }

    #[Test]
    public function anAbsolutePathInsideTheProjectIsRecordedRelativeToTheProjectRoot(): void
    {
        $written = $this->writer->write($this->report($this->project->path . '/' . self::KERNEL));

        self::assertSame($this->writer->reportPathFor(self::KERNEL), $written);
        self::assertSame(self::KERNEL, $this->decode($written)['path'], 'an absolute temp path would not be portable between machines');
    }

    #[Test]
    public function aCleanFileGetsAZeroReportSoTheAgentCanSeeTheFixLanded(): void
    {
        $written = $this->writer->write($this->report(self::KERNEL));

        $decoded = $this->decode($written);
        self::assertSame(0, $decoded['error_count']);
        self::assertSame('clean', $decoded['status']);
        self::assertSame(self::PHPSTAN, $decoded['tool']);
        self::assertSame(self::LOG, $decoded['log_path']);
    }

    #[Test]
    public function theJsonIsPrettyPrintedWithUnescapedSlashesSoJqAndAHumanBothReadItEasily(): void
    {
        $raw = \Safe\file_get_contents($this->writer->write($this->report(self::KERNEL, new FileErrorDto(12, 'a/b', 'x.y', null))));

        self::assertStringContainsString("\n    \"tool\": \"phpstan\"", $raw);
        self::assertStringContainsString('"a/b"', $raw, 'an escaped slash would make the message hard to read');
        self::assertStringEndsWith("\n", $raw);
    }

    #[Test]
    public function rewritingAFileReplacesItsReportRatherThanAppendingToIt(): void
    {
        $this->writer->write($this->report(self::KERNEL, new FileErrorDto(12, 'boom', 'x.y', null)));
        $written = $this->writer->write($this->report(self::KERNEL));

        self::assertSame(0, $this->decode($written)['error_count']);
        self::assertCount(1, $this->reportFiles());
    }

    #[Test]
    public function clearingTheWholeScopeRemovesEveryStaleReportSoAnAbsentOneMeansClean(): void
    {
        $this->writer->write($this->report(self::KERNEL, new FileErrorDto(1, 'a', null, null)));
        $this->writer->write($this->report(self::THING_TEST, new FileErrorDto(2, 'b', null, null)));
        self::assertCount(2, $this->reportFiles());

        $this->writer->clearScope(null);

        self::assertSame([], $this->reportFiles());
    }

    #[Test]
    public function clearingOneFilesScopeLeavesEveryOtherFilesReportAlone(): void
    {
        $this->writer->write($this->report(self::KERNEL, new FileErrorDto(1, 'a', null, null)));
        $this->writer->write($this->report(self::THING_TEST, new FileErrorDto(2, 'b', null, null)));

        $this->writer->clearScope($this->project->path . '/' . self::KERNEL);

        self::assertSame([$this->writer->reportPathFor(self::THING_TEST)], $this->reportFiles());
    }

    #[Test]
    public function clearingADirectoryScopeRemovesEveryReportBeneathItAndNothingOutsideIt(): void
    {
        $this->writer->write($this->report('src/Deep/Nested/Thing.php', new FileErrorDto(1, 'a', null, null)));
        $this->writer->write($this->report(self::KERNEL, new FileErrorDto(1, 'a', null, null)));
        $this->writer->write($this->report(self::THING_TEST, new FileErrorDto(2, 'b', null, null)));

        $this->writer->clearScope($this->project->path . '/src');

        self::assertSame([$this->writer->reportPathFor(self::THING_TEST)], $this->reportFiles());
    }

    #[Test]
    public function clearingAScopeThatWasNeverWrittenIsNotAnError(): void
    {
        $this->writer->clearScope($this->project->path . '/src');

        self::assertSame([], $this->reportFiles());
    }

    #[Test]
    public function theIndexListsEveryReportTheRunWroteSoStdoutNeedsOnlyOnePath(): void
    {
        $first  = $this->writer->write($this->report(self::KERNEL, new FileErrorDto(1, 'a', null, null)));
        $second = $this->writer->write($this->report('src/Other.php', new FileErrorDto(2, 'b', null, null), new FileErrorDto(3, 'c', null, null)));

        $indexPath = $this->writer->writeIndex(self::PHPSTAN, AgentStatusEnum::Errors, self::RUN_AT, 'Child process died');
        $index     = $this->decode($indexPath);

        self::assertSame($this->project->path . '/var/qa/phpstan-file-reports/_index.json', $indexPath);
        self::assertSame(self::PHPSTAN, $index['tool']);
        self::assertSame('errors', $index['status']);
        self::assertSame(1, $index['exit_code']);
        self::assertSame(4, $index['error_count'], 'the run total counts the global error as well as the three file errors');
        self::assertSame(2, $index['file_count']);
        self::assertSame(['Child process died'], $index['global_errors']);
        self::assertSame(
            [
                ['path' => self::KERNEL, 'error_count' => 1, 'report' => $first],
                ['path' => 'src/Other.php', 'error_count' => 2, 'report' => $second],
            ],
            $index['files'],
        );
    }

    #[Test]
    public function theIndexIsNotItselfListedAsAFileReport(): void
    {
        $this->writer->write($this->report(self::KERNEL));
        $this->writer->writeIndex(self::PHPSTAN, AgentStatusEnum::Clean, self::RUN_AT);

        $index = $this->decode($this->writer->writeIndex(self::PHPSTAN, AgentStatusEnum::Clean, self::RUN_AT));

        $files = $index['files'];
        self::assertIsArray($files);
        self::assertSame([self::KERNEL], array_column($files, 'path'));
    }

    #[Test]
    public function clearingAScopeAlsoRemovesTheIndexSoItCannotDescribeAPreviousRun(): void
    {
        $this->writer->write($this->report(self::KERNEL));
        $this->writer->writeIndex(self::PHPSTAN, AgentStatusEnum::Clean, self::RUN_AT);

        $this->writer->clearScope(null);

        self::assertFileDoesNotExist($this->project->path . '/var/qa/phpstan-file-reports/_index.json');
    }

    private function report(string $path, FileErrorDto ...$errors): FileReportDto
    {
        return FileReportDto::forErrors(self::PHPSTAN, $path, self::RUN_AT, self::LOG, ...$errors);
    }

    /** @return array<array-key, mixed> */
    private function decode(string $path): array
    {
        $decoded = \Safe\json_decode(\Safe\file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> every written report path, sorted */
    private function reportFiles(): array
    {
        $root = $this->project->path . '/var/qa/phpstan-file-reports';
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $walk  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $item */
        foreach ($walk as $item) {
            if ($item->isFile() && '_index.json' !== $item->getFilename()) {
                $found[] = $item->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
