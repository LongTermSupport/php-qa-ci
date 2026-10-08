<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a scheduled job whose failure nobody sees".
 *
 * Tracking the latest tools is a standing process (Plan 00020), so the dependency update runs
 * every day, and a run that fails leaves a record a person or an agent will find: a comment on the
 * one open tracking issue, or a new issue when none is open. Before, it ran weekly and a failed run
 * or a red pull request (#52) sat unseen.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class UpdateDepsRunsDailyAndReportsFailureTest extends TestCase
{
    /** The dependency update job. */
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/update-deps.yml';

    /** Reported when the schedule skips days. */
    private const string NOT_DAILY = 'the schedule does not run every day';

    /** Reported when the job cannot open or comment on an issue. */
    private const string NO_ISSUES_PERMISSION = 'the job lacks "issues: write"';

    /** Reported when nothing runs on failure. */
    private const string NO_FAILURE_REPORT = 'no step reports a failed run on the tracking issue';

    public function testTheUpdateRunsDailyAndReportsAFailedRun(): void
    {
        self::assertSame([], $this->problems(\Safe\file_get_contents(self::WORKFLOW)));
    }

    /** The guard proven to fire on a weekly job with no failure report. */
    public function testTheGuardReportsAWeeklyJobThatFailsSilently(): void
    {
        $weekly  = "on:\n  schedule:\n    - cron: '0 3 * * 0'\npermissions:\n  contents: write\n";
        $daily   = "on:\n  schedule:\n    - cron: '17 4 * * *'\npermissions:\n  contents: write\n  issues: write\n";
        $reports = "      - name: Report the failure\n        if: failure()\n        uses: actions/github-script@v9\n";

        self::assertSame([self::NOT_DAILY, self::NO_ISSUES_PERMISSION, self::NO_FAILURE_REPORT], $this->problems($weekly));
        self::assertSame([self::NO_FAILURE_REPORT], $this->problems($daily));
        self::assertSame([], $this->problems($daily . $reports));
    }

    /** @return list<string> */
    private function problems(string $workflow): array
    {
        $problems = [];
        if (1 !== \Safe\preg_match("/- cron: '\\S+ \\S+ \\* \\* \\*'/", $workflow)) {
            $problems[] = self::NOT_DAILY;
        }

        if (1 !== \Safe\preg_match('/^  issues: write$/m', $workflow)) {
            $problems[] = self::NO_ISSUES_PERMISSION;
        }

        if (1 !== \Safe\preg_match('/if: failure\(\)\n\s+uses: actions\/github-script@/', $workflow)) {
            $problems[] = self::NO_FAILURE_REPORT;
        }

        return $problems;
    }
}
