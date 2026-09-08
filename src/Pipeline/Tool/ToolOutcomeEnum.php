<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

/**
 * How a tool run ended.
 *
 * @internal
 */
enum ToolOutcomeEnum
{
    public function isSuccess(): bool
    {
        return match ($this) {
            self::Passed, self::Skipped => true,
            self::Failed, self::Crashed => false,
        };
    }

    public function isRetryable(): bool
    {
        return self::Failed === $this;
    }

    /** The tool ran and found nothing to report. */
    case Passed;
    /** The tool ran and reported violations; interactively this is retried. */
    case Failed;
    /** The tool itself broke (bad config, parse failure); never retried. */
    case Crashed;
    /** The tool decided not to run (opted out, nothing to do). Counts as passed. */
    case Skipped;
}
