<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The PHPStan redirect stub must make NOISE about a misconfigured Claude
 * hooks-daemon. The daemon's default extended PHP lint command is
 * `phpstan analyse {file}`, which lands on the stub and can never pass —
 * false-failing every PHP edit in the session. php-qa-ci ships the stubs, so
 * php-qa-ci owns telling the operator the exact `.claude/hooks-daemon.yaml`
 * override that routes the lint through `qa -t phpstan -p {file}` instead.
 *
 * The notice must appear ONLY when a hooks-daemon config exists AND lacks the
 * override — a correctly configured (or daemon-less) project keeps the
 * original terse stub output.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class RedirectStubHooksDaemonNoticeTest extends TestCase
{
    private const string PHPSTAN_STUB = __DIR__ . '/../../../bin/phpstan';

    private const string MANAGED_NOTICE = 'is managed by php-qa-ci';

    private const string DAEMON_NOTICE = 'HOOKS DAEMON DETECTED';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/stubNotice' . uniqid('', true);
        \Safe\mkdir($this->projectDir . '/vendor/bin', 0o755, true);
        \Safe\file_put_contents($this->projectDir . '/composer.json', "{}\n");
    }

    public function testNoticePrintedWhenDaemonConfigLacksTheLintOverride(): void
    {
        \Safe\mkdir($this->projectDir . '/.claude', 0o755, true);
        \Safe\file_put_contents(
            $this->projectDir . '/.claude/hooks-daemon.yaml',
            "handlers:\n  post_tool_use:\n    lint_on_edit:\n      enabled: true\n",
        );

        [$exitCode, $output] = $this->runStub();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(self::MANAGED_NOTICE, $output);
        self::assertStringContainsString(self::DAEMON_NOTICE, $output);
        self::assertStringContainsString('command_overrides', $output);
        self::assertStringContainsString('qa -t phpstan -p {file}', $output);
    }

    public function testNoNoticeWhenDaemonConfigAlreadyHasTheLintOverride(): void
    {
        \Safe\mkdir($this->projectDir . '/.claude', 0o755, true);
        \Safe\file_put_contents(
            $this->projectDir . '/.claude/hooks-daemon.yaml',
            "handlers:\n  post_tool_use:\n    lint_on_edit:\n      options:\n"
            . "        command_overrides:\n          PHP:\n"
            . "            extended: \"qa -t phpstan -p {file}\"\n",
        );

        [$exitCode, $output] = $this->runStub();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(self::MANAGED_NOTICE, $output);
        self::assertStringNotContainsString(self::DAEMON_NOTICE, $output);
    }

    public function testNoNoticeWhenNoDaemonConfigExists(): void
    {
        [$exitCode, $output] = $this->runStub();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(self::MANAGED_NOTICE, $output);
        self::assertStringNotContainsString(self::DAEMON_NOTICE, $output);
    }

    /**
     * @return array{int, string}
     */
    private function runStub(): array
    {
        $cmd = \sprintf(
            'COMPOSER_RUNTIME_BIN_DIR=%s bash %s 2>&1',
            escapeshellarg($this->projectDir . '/vendor/bin'),
            escapeshellarg(self::PHPSTAN_STUB),
        );

        $output   = [];
        $exitCode = 0;
        \Safe\exec($cmd, $output, $exitCode);

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
