<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;

/**
 * Whether a phpstan.phar run is a crash rather than a verdict, and why.
 *
 * PHPStan exits 1 both when it reports errors and when it abandons the analysis on internal
 * errors. An abandoned analysis drops every real finding and reports only the internal errors,
 * so reading it as findings misreports the run; its own stderr line is what tells the two apart.
 * Any exit above 1 is a crash outright.
 *
 * @internal
 */
final readonly class PhpstanCrash
{
    /** What PHPStan writes to stderr when it abandons an analysis. */
    public const string INCOMPLETE_MARKER = 'Result is incomplete because of severe errors';

    /** The crash reason for an abandoned analysis. */
    public const string INCOMPLETE_REASON = 'PHPStan abandoned the analysis on internal errors (result incomplete)';

    /** The crash reason for any exit above 1. */
    private const string EXIT_REASON = 'PHPStan crashed (exit %d)';

    /** The reason the run is a crash, or null when it reached a verdict. */
    public static function reason(ProcessResultDto $result): ?string
    {
        if ($result->exitCode > 1) {
            return \sprintf(self::EXIT_REASON, $result->exitCode);
        }

        if (1 === $result->exitCode && 1 === \Safe\preg_match('/^.*' . preg_quote(self::INCOMPLETE_MARKER, '/') . '\.\s*⚠️\s*$/mu', $result->output)) {
            return self::INCOMPLETE_REASON;
        }

        return null;
    }
}
