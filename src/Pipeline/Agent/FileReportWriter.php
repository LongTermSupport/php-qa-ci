<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

use FilesystemIterator;
use LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Owns the per-file report tree under `var/qa/<tool>-file-reports/`.
 *
 * The report for a source file sits at the source file's own project-relative
 * path with `.json` appended, extension included, so the mapping is reversible
 * by eye and two source files can never share a report.
 *
 * `clearScope()` is what stops a fixed error living on as a red report.
 * PHPStan's JSON names only the files that HAVE errors, so absence alone
 * cannot distinguish "clean" from "not analysed". Clearing the scope the run
 * is about to analyse before writing anything makes absence mean clean, since
 * every report left behind was written by this run.
 *
 * @internal
 */
final class FileReportWriter
{
    public const string INDEX_FILE = '_index.json';

    private const string REPORT_SUFFIX = '.json';

    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /** @var array<string, array{path: string, error_count: int, report: string}> keyed by project-relative source path, in write order */
    private array $written = [];

    public function __construct(private readonly string $reportDir, private readonly string $projectRoot)
    {
    }

    /** The report path for a source file, given either relative to the project root or absolute. */
    public function reportPathFor(string $path): string
    {
        return $this->reportDir . '/' . $this->relative($path) . self::REPORT_SUFFIX;
    }

    /**
     * Remove every report the next run could otherwise leave stale: the whole
     * tree for a full run, or the subtree of the specified path for a `-p`
     * run. The index goes too, because an index naming reports that no longer
     * exist is worse than none.
     */
    public function clearScope(?string $scope): void
    {
        if (!is_dir($this->reportDir)) {
            return;
        }

        $this->removeIfPresent($this->indexPath());

        if (null === $scope) {
            foreach ($this->existingReports($this->reportDir) as $report) {
                $this->removeIfPresent($report);
            }

            return;
        }

        $relative = $this->relative($scope);
        $asFile   = $this->reportDir . '/' . $relative . self::REPORT_SUFFIX;
        $this->removeIfPresent($asFile);

        $asDirectory = $this->reportDir . '/' . $relative;
        if (is_dir($asDirectory)) {
            foreach ($this->existingReports($asDirectory) as $report) {
                $this->removeIfPresent($report);
            }
        }
    }

    /** Write (or replace) one file's report and return its absolute path. */
    public function write(FileReportDto $report): string
    {
        $relative = $this->relative($report->path);
        $target   = $this->reportDir . '/' . $relative . self::REPORT_SUFFIX;
        $this->mkdirFor($target);

        \Safe\file_put_contents($target, $report->withPath($relative)->toJson());

        $this->written[$relative] = ['path' => $relative, 'error_count' => $report->errorCount(), 'report' => $target];

        return $target;
    }

    /**
     * Write the run-level index over everything written since construction, so
     * a terse stdout can name one path however many files had findings.
     *
     * `$globalErrors` are the findings that belong to the run rather than to
     * any one file.
     */
    public function writeIndex(string $tool, AgentStatusEnum $status, int $runAt, string ...$globalErrors): string
    {
        $globalErrors = array_values($globalErrors);
        $files        = array_values($this->written);
        $index        = [
            'tool'          => $tool,
            'status'        => $status->value,
            'run_at'        => gmdate(FileReportDto::TIMESTAMP_FORMAT, $runAt),
            'exit_code'     => $status->exitCode(),
            'error_count'   => array_sum(array_column($files, 'error_count')) + \count($globalErrors),
            'file_count'    => \count($files),
            'global_errors' => $globalErrors,
            'files'         => $files,
        ];

        $target = $this->indexPath();
        $this->mkdirFor($target);
        \Safe\file_put_contents($target, \Safe\json_encode($index, self::JSON_FLAGS) . "\n");

        return $target;
    }

    public function indexPath(): string
    {
        return $this->reportDir . '/' . self::INDEX_FILE;
    }

    /** A path inside the project, relative to its root; anything else is used as-is. */
    private function relative(string $path): string
    {
        $prefix = $this->projectRoot . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : ltrim($path, '/');
    }

    /** @return list<string> every report file under $root, deepest last */
    private function existingReports(string $root): array
    {
        $found = [];
        $walk  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $item */
        foreach ($walk as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), self::REPORT_SUFFIX)) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }

    private function removeIfPresent(string $path): void
    {
        if (is_file($path)) {
            \Safe\unlink($path);
        }
    }

    private function mkdirFor(string $target): void
    {
        $dir = \dirname($target);
        if (!is_dir($dir)) {
            \Safe\mkdir($dir, 0o777, true);
        }
    }
}
