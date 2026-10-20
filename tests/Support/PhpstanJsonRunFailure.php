<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;

/**
 * Why a phpstan.phar run with --error-format=json is not a clean analysis, for
 * a test that expects findings but no error of PHPStan's own.
 *
 * PhpstanCrash finds a crash, and an internal error by the "Result is
 * incomplete" line. The JSON `errors` list, PHPStan's errors that belong to no
 * file, must also be empty: it holds every internal error, and general errors
 * that come with no incomplete line, such as an ignoreErrors pattern nothing
 * matched.
 *
 * @internal
 */
final readonly class PhpstanJsonRunFailure
{
    private const string GENERAL_ERRORS_REASON = 'PHPStan reported errors that belong to no file:';

    private const string NO_REPORT_REASON = 'PHPStan wrote no JSON report, so the run reached no verdict';

    /** The reason the run failed, or null when it reached a verdict and reported no general error. */
    public static function reason(ProcessResultDto $result): ?string
    {
        $crash = PhpstanCrash::jsonReason($result);
        if (null !== $crash) {
            return $crash;
        }

        $generalErrors = self::generalErrors($result->stdout);
        if (null === $generalErrors) {
            return self::NO_REPORT_REASON;
        }

        return [] === $generalErrors ? null : self::GENERAL_ERRORS_REASON . "\n" . implode("\n", $generalErrors);
    }

    /**
     * The entries of the JSON report's `errors` list, or null when the output
     * is not a PHPStan report: an object with a `totals` object and an
     * `errors` list.
     *
     * @return list<string>|null
     */
    private static function generalErrors(string $stdout): ?array
    {
        if (!json_validate($stdout)) {
            return null;
        }

        $report = \Safe\json_decode($stdout, true);
        if (!\is_array($report) || !\is_array($report['totals'] ?? null)) {
            return null;
        }

        $errors = $report['errors'] ?? null;
        if (!\is_array($errors) || !array_is_list($errors)) {
            return null;
        }

        return array_map(
            static fn (mixed $error): string => \is_string($error) ? $error : get_debug_type($error),
            $errors,
        );
    }
}
