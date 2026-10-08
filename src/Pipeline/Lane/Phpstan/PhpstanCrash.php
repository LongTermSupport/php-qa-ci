<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;

/**
 * Whether a phpstan.phar run is a crash rather than a verdict, and why.
 *
 * PHPStan exits 1 for findings, for an analysis it abandoned on internal errors (every real
 * finding is dropped), and for an error before any analysis: a config error, a missing bootstrap
 * file, a rule class it cannot load. So an exit 1 is findings only when the output says PHPStan
 * found some, and the abandoned case is named by the line it writes to stderr. Any exit above 1 is
 * a crash outright. PhpstanOutcomesFromTheShippedPharTest holds these lines to the shipped phar.
 *
 * @internal
 */
final readonly class PhpstanCrash
{
    /** What PHPStan writes to stderr when it abandons an analysis. */
    public const string INCOMPLETE_MARKER = 'Result is incomplete because of severe errors';

    /** The crash reason for an abandoned analysis. */
    public const string INCOMPLETE_REASON = 'PHPStan abandoned the analysis on internal errors (result incomplete)';

    /** The crash reason for an exit 1 that reports no findings. */
    public const string NO_REPORT_REASON = 'PHPStan exited 1 without reporting any findings (an error before or outside the analysis; see its output)';

    /** The crash reason for any exit above 1. */
    private const string EXIT_REASON = 'PHPStan crashed (exit %d)';

    /** The line the table output ends a findings run with. */
    private const string FINDINGS_SUMMARY = '/^\s*\[ERROR\] Found [1-9]\d* errors?\s*$/m';

    /** The reason a run in PHPStan's table format is a crash, or null when it reached a verdict. */
    public static function reason(ProcessResultDto $result): ?string
    {
        $early = self::exitReason($result);
        if (null !== $early || 1 !== $result->exitCode) {
            return $early;
        }

        return 1 === \Safe\preg_match(self::FINDINGS_SUMMARY, $result->output) ? null : self::NO_REPORT_REASON;
    }

    /** The same for a run with --error-format=json, whose report is the process's stdout. */
    public static function jsonReason(ProcessResultDto $result): ?string
    {
        $early = self::exitReason($result);
        if (null !== $early || 1 !== $result->exitCode) {
            return $early;
        }

        return self::jsonReportsFindings($result->stdout) ? null : self::NO_REPORT_REASON;
    }

    /** A crash by exit code or by the abandoned-analysis line; null leaves the verdict to the format. */
    private static function exitReason(ProcessResultDto $result): ?string
    {
        if ($result->exitCode > 1) {
            return \sprintf(self::EXIT_REASON, $result->exitCode);
        }

        if (1 === $result->exitCode && 1 === \Safe\preg_match('/^.*' . preg_quote(self::INCOMPLETE_MARKER, '/') . '\.\s*⚠️\s*$/mu', $result->output)) {
            return self::INCOMPLETE_REASON;
        }

        return null;
    }

    private static function jsonReportsFindings(string $stdout): bool
    {
        if (!json_validate($stdout)) {
            return false;
        }

        $report = \Safe\json_decode($stdout, true);
        if (!\is_array($report) || !\is_array($report['totals'] ?? null)) {
            return false;
        }

        $totals = $report['totals'];

        return (\is_int($totals['errors'] ?? null) && $totals['errors'] > 0)
            || (\is_int($totals['file_errors'] ?? null) && $totals['file_errors'] > 0);
    }
}
