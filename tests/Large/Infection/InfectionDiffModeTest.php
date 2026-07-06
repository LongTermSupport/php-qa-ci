<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Infection;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two-mode flag contract of includes/generic/infection.inc.bash's
 * runInfection():
 *
 *   - FULL mode (infectionDiffBase unset) must build the historic whole-codebase
 *     invocation — the SSoT floors (--min-msi / --min-covered-msi) plus the
 *     verbose console report — byte-for-byte, so the default gate is unchanged.
 *   - DIFF mode (infectionDiffBase set) must instead scope the run to the changed
 *     files via --filter, enforce the "no new escaped mutants" bar
 *     (--min-covered-msi=100), and drop the verbose report.
 *
 * The function is extracted from the include and evaluated with a stubbed runner
 * so the arguments Infection would receive are captured without running the phar
 * or generating coverage — the same exec-a-subprocess shape the other
 * include-level Large tests use.
 *
 * @internal
 */
#[Large]
#[CoversNothing]
final class InfectionDiffModeTest extends TestCase
{
    private const string INCLUDE = __DIR__ . '/../../../includes/generic/infection.inc.bash';

    public function testFullModeBuildsTheHistoricFloorInvocation(): void
    {
        $args = $this->captureInfectionArgs(diffBase: null);

        self::assertContains('--min-msi=74', $args, "full run must enforce the SSoT MSI floor:\n" . implode("\n", $args));
        self::assertContains('--min-covered-msi=76', $args, 'full run must enforce the SSoT covered-MSI floor');
        self::assertContains('--log-verbosity=all', $args, 'full run keeps the verbose console report');

        // The diff-only flags must be absent when no base ref is configured.
        self::assertNotContains('--min-covered-msi=100', $args, 'the diff bar must not leak into a full run');
        foreach ($args as $arg) {
            self::assertStringStartsNotWith('--git-diff', $arg, 'a full run must not be git-diff scoped');
        }
    }

    public function testDiffModeScopesToChangedFilesAndEnforcesNoNewEscapes(): void
    {
        $args = $this->captureInfectionArgs(diffBase: 'origin/main');

        self::assertContains('--filter=src/Changed.php', $args, "diff run must scope to the changed files:\n" . implode("\n", $args));
        self::assertContains('--min-covered-msi=100', $args, 'diff run enforces "no new escaped mutants in changed code"');

        // The full-run floors and verbose report must NOT appear in diff mode.
        self::assertNotContains('--min-msi=74', $args, 'the whole-codebase floor is meaningless on a diff and must be dropped');
        self::assertNotContains('--min-covered-msi=76', $args, 'the whole-codebase covered floor must be dropped in diff mode');
        self::assertNotContains('--log-verbosity=all', $args, 'diff mode drops the verbose console report (file loggers stay authoritative)');
    }

    /**
     * Extract runInfection() from the include and evaluate it with a stubbed
     * phpNoXdebug that prints each argument on its own line, so we observe exactly
     * the flags Infection would be invoked with.
     *
     * @return list<string>
     */
    private function captureInfectionArgs(?string $diffBase): array
    {
        $harness = <<<'BASH'
            set -euo pipefail
            include="$1"
            # Stub the runner: print each argument on its own line.
            phpNoXdebug() { printf '%s\n' "$@"; }
            # Minimal environment runInfection reads (values are arbitrary but fixed).
            infectionPath=/phar/infection.phar
            varDir=/var/qa
            infectionThreads=4
            infectionConfig=/cfg/infection.json
            minMsi=74
            minCoveredMsi=76
            infectionOnlyCovered=0
            if [[ -n "${2:-}" ]]; then
                infectionDiffBase="$2"
                # In a real run the include computes this list from `git diff`; the
                # unit under test here is runInfection's flag assembly, so we inject
                # a fixed changed-file list.
                infectionDiffFilter="src/Changed.php"
            fi
            # Pull ONLY the runInfection function out of the include (its top-level
            # code generates coverage and is not what we are pinning here).
            eval "$(awk '/^function runInfection\(\) \{/{p=1} p{print} p&&/^\}/{exit}' "$include")"
            runInfection
            BASH;

        $harnessFile = (string) tempnam(sys_get_temp_dir(), 'infDiff');
        \Safe\file_put_contents($harnessFile, $harness . "\n");

        $cmd = \sprintf(
            'bash %s %s %s 2>&1',
            escapeshellarg($harnessFile),
            escapeshellarg(self::INCLUDE),
            escapeshellarg($diffBase ?? ''),
        );

        $output   = [];
        $exitCode = 0;
        \Safe\exec($cmd, $output, $exitCode);
        \Safe\unlink($harnessFile);

        self::assertSame(0, $exitCode, "harness failed:\n" . implode("\n", $output));

        $args = [];
        foreach ($output as $line) {
            if (\is_string($line)) {
                $args[] = $line;
            }
        }

        return $args;
    }
}
