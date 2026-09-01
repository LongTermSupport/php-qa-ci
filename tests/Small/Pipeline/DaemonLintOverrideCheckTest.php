<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Deploy-time check (scripts/lib/daemon-lint-override-check.inc.bash): when a
 * consuming project has a Claude hooks-daemon config that lacks the PHP
 * extended-lint override, deploy-skills must print the exact YAML to add —
 * the daemon's default `phpstan analyse {file}` hits php-qa-ci's redirect
 * stub and false-fails every PHP edit. The check instructs (for a human or
 * agent to apply); it deliberately never edits the daemon YAML itself.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class DaemonLintOverrideCheckTest extends TestCase
{
    private const string CHECK_LIB = __DIR__ . '/../../../scripts/lib/daemon-lint-override-check.inc.bash';

    public function testInstructsWhenOverrideMissing(): void
    {
        $configFile = $this->writeConfig(
            "handlers:\n  post_tool_use:\n    lint_on_edit:\n      enabled: true\n",
        );

        [$exitCode, $output] = $this->runCheck($configFile);

        self::assertSame(0, $exitCode, 'Check is advisory — it must never fail the deployment');
        self::assertStringContainsString('ACTION REQUIRED', $output);
        self::assertStringContainsString('command_overrides', $output);
        self::assertStringContainsString('qa -t phpstan -p {file}', $output);
        self::assertStringContainsString('/hooks-daemon restart', $output);
    }

    public function testSilentConfirmationWhenOverridePresent(): void
    {
        $configFile = $this->writeConfig(
            "handlers:\n  post_tool_use:\n    lint_on_edit:\n      options:\n"
            . "        command_overrides:\n          PHP:\n"
            . "            extended: \"qa -t phpstan -p {file}\"\n",
        );

        [$exitCode, $output] = $this->runCheck($configFile);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('ACTION REQUIRED', $output);
        self::assertStringContainsString('lint override', $output);
    }

    public function testNoOutputForMissingConfigFile(): void
    {
        [$exitCode, $output] = $this->runCheck(sys_get_temp_dir() . '/no-such-daemon-config.yaml');

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
    }

    private function writeConfig(string $yaml): string
    {
        $configFile = \Safe\tempnam(sys_get_temp_dir(), 'daemonLintCheck');
        \Safe\file_put_contents($configFile, $yaml);

        return $configFile;
    }

    /**
     * @return array{int, string}
     */
    private function runCheck(string $configFile): array
    {
        $harness = <<<'BASH'
            set -u
            source "$1"
            phpQaCiDaemonLintOverrideCheck "$2"
            BASH;

        $harnessFile = \Safe\tempnam(sys_get_temp_dir(), 'daemonLintHarness');
        \Safe\file_put_contents($harnessFile, $harness . "\n");

        $cmd = \sprintf(
            'bash %s %s %s 2>&1',
            escapeshellarg($harnessFile),
            escapeshellarg(self::CHECK_LIB),
            escapeshellarg($configFile),
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

        return [$exitCode, implode("\n", $outputLines)];
    }
}
