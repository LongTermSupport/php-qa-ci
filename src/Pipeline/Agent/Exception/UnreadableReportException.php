<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent\Exception;

use RuntimeException;

/**
 * A tool's structured output could not be read as the report agent mode
 * expects.
 *
 * This is deliberately fatal. Agent mode's whole value is that the terse
 * stdout is trustworthy, and output that cannot be parsed most often means
 * the analyser died mid-run — treating it as "no findings" would report a
 * green the run never earned.
 *
 * @internal
 */
final class UnreadableReportException extends RuntimeException
{
    private const string TEMPLATE = '%s produced output agent mode could not read as a report: %s';

    public static function forTool(string $tool, string $reason): self
    {
        return new self(\sprintf(self::TEMPLATE, $tool, $reason));
    }
}
