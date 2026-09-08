<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Pins the failure output of includes/functions.inc.bash naming the method
 * and linking its canonical specification. A red pipeline is the moment the
 * reader needs to know WHY the gate is shaped the way it is, so every failure
 * path (the tryAgainOrAbort banner and the aggregate-mode summary) carries
 * exactly one line pointing at the Defence Before Fix specification. Success
 * paths must stay silent about it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class FailureOutputNamesTheMethodTest extends TestCase
{
    private const string FUNCTIONS = __DIR__ . '/../../../includes/functions.inc.bash';

    private const string METHOD_LINE = 'Defence Before Fix: https://defence-before-fix.github.io/';

    public function testTryAgainOrAbortInCiPrintsTheMethodLineAndExitsOne(): void
    {
        [$exitCode, $output] = $this->runHarness(
            'CI=true tryAgainOrAbort "PHPStan"',
        );

        self::assertSame(1, $exitCode);
        self::assertSame(
            [
                '',
                '',
                '    ==================================================',
                '',
                '        PHPStan Failed...',
                '',
                '        ' . self::METHOD_LINE,
                '',
                '    ==================================================',
                '',
                '',
            ],
            $output,
        );
    }

    public function testAggregateSummaryWithOneFailedToolPrintsTheMethodLine(): void
    {
        [$exitCode, $output] = $this->runHarness(
            'qaAggregate=true; qaFailedTools=("phpstan"); qaReportAggregate',
        );

        self::assertSame(1, $exitCode);
        self::assertSame(
            [
                '',
                '    ==================================================',
                '',
                '        Aggregate (read-only) run: 1 tool(s) FAILED',
                '',
                '',
                '          - phpstan',
                '',
                '        ' . self::METHOD_LINE,
                '',
                "        Each tool's full output is above. Fix every item, then re-run.",
                '        (This run did not fail fast: all tools ran so you see every problem.)',
                '',
                '    ==================================================',
                '',
            ],
            $output,
        );
    }

    public function testAggregateSummaryWithNoFailedToolDoesNotPrintTheMethodLine(): void
    {
        [$exitCode, $output] = $this->runHarness(
            'qaAggregate=true; qaFailedTools=(); qaReportAggregate',
        );

        self::assertSame(0, $exitCode);
        self::assertSame(
            [
                '',
                '    ==================================================',
                '',
                '        Aggregate (read-only) run: every QA tool passed.',
                '',
                '    ==================================================',
                '',
            ],
            $output,
        );
        self::assertNotContains('        ' . self::METHOD_LINE, $output);
    }

    /**
     * Sources functions.inc.bash then runs the given bash snippet, returning
     * the exit code and the output lines (stdout and stderr merged). Mirrors
     * the harness in SpecifiedPathNormalisationTest: every argument is passed
     * through escapeshellarg and nothing comes from outside this test class.
     *
     * @return array{int, list<string>}
     */
    private function runHarness(string $snippet): array
    {
        $harness = <<<BASH
            set -u
            source "\$1"
            {$snippet}
            BASH;

        $harnessFile = \Safe\tempnam(sys_get_temp_dir(), 'qaFailureOutput');
        \Safe\file_put_contents($harnessFile, $harness . "\n");

        $cmd = \sprintf(
            'bash %s %s 2>&1',
            escapeshellarg($harnessFile),
            escapeshellarg(self::FUNCTIONS),
        );

        $output   = [];
        $exitCode = 0;
        \Safe\exec($cmd, $output, $exitCode);
        \Safe\unlink($harnessFile);

        $exitCode ??= 0;
        $outputLines = [];
        foreach ($output ?? [] as $line) {
            if (\is_string($line)) {
                $outputLines[] = $line;
            }
        }

        return [$exitCode, $outputLines];
    }
}
