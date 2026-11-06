<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\ShellCheck;

use LTS\PHPQA\Pipeline\Lane\ShellCheckTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * The shellCheck lane, with the real pinned ShellCheck over a real git work tree, leaves out a
 * script the Claude Code hooks daemon generated (SC2034 in it is not the project's to fix) and
 * still fails on a hand-written script in the same directory with the same defect.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShellCheckSkipsDaemonOwnedFilesTest extends TestCase
{
    private const string STUB = '.claude/hooks/stop';

    private const string HAND_WRITTEN = '.claude/hooks/mine';

    private const string UNUSED = "unused=1\n";

    /** @var array<string, string|false> the host values of the isolating variables, restored afterwards */
    private array $hostEnvironment = [];

    private TempDir $project;

    protected function setUp(): void
    {
        foreach (GitSandbox::environment() as $name => $value) {
            $this->hostEnvironment[$name] = getenv($name);
            \Safe\putenv($name . '=' . $value);
        }

        $this->project = TempDir::create('phpqa-shellcheck-daemon');
        $this->project->write(self::STUB, "#!/bin/bash\n#\n# DAEMON-OWNED FILE - do not edit. Refreshed on every upgrade.\n#\n" . self::UNUSED);
        $this->project->write(self::HAND_WRITTEN, "#!/bin/bash\n" . self::UNUSED);
        $this->git('init', '--initial-branch=php8.5');
        $this->git('add', '-A');
        $this->git('commit', '-m', 'Initial');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        foreach ($this->hostEnvironment as $name => $value) {
            \Safe\putenv(false === $value ? $name : $name . '=' . $value);
        }
    }

    #[Test]
    public function theGeneratedStubIsSkippedAndTheHandWrittenScriptBesideItFails(): void
    {
        $output = new BufferedOutput();

        $result  = new ShellCheckTool()->run(RealLaneContext::over($this->project->path, false, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('1 file(s) skipped: generated or installed by the Claude Code hooks daemon', $printed);
        self::assertStringContainsString(self::HAND_WRITTEN, $printed);
        self::assertStringContainsString('SC2034', $printed);
        self::assertStringNotContainsString(self::STUB, $printed);
    }

    #[Test]
    public function aProjectWhoseOnlyDefectiveScriptIsTheGeneratedStubPasses(): void
    {
        $this->git('rm', '-q', '-f', self::HAND_WRITTEN);
        $output = new BufferedOutput();

        $result = new ShellCheckTool()->run(RealLaneContext::over($this->project->path, false, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('1 file(s) skipped', $output->fetch());
    }

    private function git(string ...$args): void
    {
        new Process(['git', ...array_values($args)], $this->project->path, GitSandbox::environment())->mustRun();
    }
}
