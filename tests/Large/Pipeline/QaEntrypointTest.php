<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Characterisation of the PHP entrypoint end-to-end: the real script, the real
 * argument parsing and usage text, over this repository as the project. The
 * cases here need no lane to be ported; each ported lane adds its own.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class QaEntrypointTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    #[Test]
    public function helpPrintsTheUsageToStderrAndExitsOne(): void
    {
        $process = $this->qa([], '-h');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Usage:', $process->getErrorOutput());
        self::assertStringContainsString('stan|phpstan', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    #[Test]
    public function anInvalidToolPrintsTheErrorAndTheUsage(): void
    {
        $process = $this->qa([], '-t', 'nope');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Invalid tool: nope', $process->getErrorOutput());
        self::assertStringContainsString('Usage:', $process->getErrorOutput());
    }

    #[Test]
    public function aPathWithANonPathToolIsRefusedWithoutTheUsage(): void
    {
        $process = $this->qa([], '-t', 'cr', '-p', 'src');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("Tool 'cr' does not support path-specific execution", $process->getErrorOutput());
        self::assertStringNotContainsString('Usage:', $process->getErrorOutput());
    }

    #[Test]
    public function aPathOutsideTheProjectIsRefused(): void
    {
        $process = $this->qa([], '-t', 'stan', '-p', '/tmp');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('is outside the project root', $process->getErrorOutput());
    }

    #[Test]
    public function theRunHeaderReportsModesPlatformAndXdebugThenRefusesThisRepositorysBashConfig(): void
    {
        $process = $this->qa(['QA_READONLY' => '1'], '-t', 'stan');

        $out = $process->getOutput();
        self::assertStringContainsString('QA read-only (check) mode', $out);
        self::assertStringContainsString('QA aggregate mode', $out);
        self::assertStringContainsString('generic platform detected', $out);
        self::assertStringContainsString('Checking for Xdebug', $out);
        // This repository still carries qaConfig/qaConfig.inc.bash for the Bash bin/qa;
        // the PHP entrypoint refuses it with the migration guidance (Plan 00003 Phase 4
        // replaces the file with qa.php and this assertion with a real run).
        self::assertStringContainsString('qaConfig.inc.bash is no longer read', $out);
        self::assertSame(1, $process->getExitCode());
    }

    /** @param array<string, string> $env */
    private function qa(array $env, string ...$args): Process
    {
        $process = new Process(['php', self::ROOT . '/bin/qa-php', ...$args], self::ROOT, ['CI' => 'true', ...$env], null, 120);
        $process->run();

        return $process;
    }
}
