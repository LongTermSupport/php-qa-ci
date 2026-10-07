<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator;
use LTS\PHPQA\Pipeline\Lane\MarkdownFormatTool;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The lane hands markdown to the hooks daemon's own formatter, so what it
 * writes is what the daemon would write; it never formats anything itself.
 *
 * @internal
 */
#[CoversClass(MarkdownFormatTool::class)]
#[UsesClass(HooksDaemonCliLocator::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class MarkdownFormatToolTest extends TestCase
{
    private const string README = 'README.md';

    private const string DOCS = 'docs';

    private const string CHECK = '--check';

    private const string FORMAT = 'format-markdown';

    private const string PENDING_README = 'Would reformat: README.md';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->mkdir('.git');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function withoutTheDaemonTheLaneSkipsAndSaysWhy(): void
    {
        $this->writeReadme();

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('the hooks daemon is not installed, and its formatter is the one this lane runs', $result->summary);
        self::assertSame([], $this->factory->processes->specs, 'nothing may run without the daemon');
        self::assertStringContainsString(HooksDaemonCliLocator::CLI, $this->factory->output->fetch());
    }

    #[Test]
    public function withNoneOfTheConfiguredPathsPresentTheLaneSkips(): void
    {
        $this->installDaemon();

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('no markdown path to format: each is absent or gitignored', $result->summary);
        self::assertStringContainsString('Not present, skipped: README.md', $this->factory->output->fetch());
    }

    #[Test]
    public function aReadOnlyRunChecksEachPresentPathWithTheDaemonsFormatter(): void
    {
        $cli = $this->installDaemon();
        $this->writeReadme();
        $this->factory->project->write('docs/guide.md', "# Guide\n");
        $this->noneIgnored()->willSucceed()->willSucceed();

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([
            [$cli, self::FORMAT, self::CHECK, self::README],
            [$cli, self::FORMAT, self::CHECK, self::DOCS],
        ], $this->commands(), 'one call per present path, in configured order; absent defaults are dropped');
        self::assertSame($this->factory->project->path, $this->factory->processes->lastSpec()->cwd);
    }

    #[Test]
    public function aWritableRunFormatsInPlace(): void
    {
        $cli = $this->installDaemon();
        $this->writeReadme();
        $this->noneIgnored()->willSucceed('Reformatted: README.md');

        $result = new MarkdownFormatTool()->run($this->context(readOnly: false));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([[$cli, self::FORMAT, self::README]], $this->commands());
        self::assertStringContainsString('Reformatted: README.md', $this->factory->output->fetch());
    }

    #[Test]
    public function aPendingReformatInAReadOnlyRunFailsWithTheWouldModifyGuidance(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->noneIgnored()->willFail(1, self::PENDING_README);

        $result  = new MarkdownFormatTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(self::PENDING_README, $printed);
        self::assertStringContainsString('READ-ONLY run', $printed);
        self::assertStringContainsString('vendor/bin/qa -t mdf', $printed);
        self::assertStringContainsString(MarkdownFormatTool::IDENTIFIER, $printed);
    }

    /** Every path is checked before the verdict, so one run lists every file to fix. */
    #[Test]
    public function aReadOnlyRunReportsEveryPathBeforeFailing(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->factory->project->write('docs/guide.md', "# Guide\n");
        $this->noneIgnored()->willFail(1, self::PENDING_README)->willFail(1, 'Would reformat: docs/guide.md');

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(2, $this->commands());
        self::assertStringContainsString('Would reformat: docs/guide.md', $this->factory->output->fetch());
    }

    /** `--check` exits 1 for an error too; only "Would reformat" lines make it a finding. */
    #[Test]
    public function aFailureThatNamesNoFileToReformatIsACrash(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->noneIgnored()->willFail(1, 'ERROR: README.md is gitignored');

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('the hooks daemon formatter could not run (exit 1)', $result->summary);
        self::assertStringContainsString('ERROR: README.md is gitignored', $this->factory->output->fetch());
    }

    #[Test]
    public function aWritableRunWhoseFormatterFailsIsACrash(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->noneIgnored()->willFail(2, 'usage: claude-hooks-daemon');

        $result = new MarkdownFormatTool()->run($this->context(readOnly: false));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('the hooks daemon formatter could not run (exit 2)', $result->summary);
    }

    #[Test]
    public function aProjectChoosesItsOwnPaths(): void
    {
        $cli = $this->installDaemon();
        $this->writeReadme();
        $this->factory->project->write('handbook/intro.md', "# Intro\n");
        $this->noneIgnored()->willSucceed();

        $config = $this->factory->builder()->withMarkdownFormatPaths('handbook')->build();
        new MarkdownFormatTool()->run($this->factory->context($config));

        self::assertSame([[$cli, self::FORMAT, self::CHECK, 'handbook']], $this->commands(), 'the project list replaces the defaults');
    }

    /**
     * The daemon refuses a gitignored path it is handed by name, so a default
     * the project ignores (generated API docs in `docs/`) is dropped, not run.
     */
    #[Test]
    public function aPathGitIgnoresIsDroppedAndNamed(): void
    {
        $cli = $this->installDaemon();
        $this->writeReadme();
        $this->factory->project->write('docs/api.md', "# Api\n");
        $this->factory->processes->willSucceed(self::DOCS . "\n")->willSucceed();

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(['git', '-c', 'core.quotePath=false', 'check-ignore', '--', self::README, self::DOCS], $this->factory->processes->specs[0]->command);
        self::assertSame($this->factory->project->path, $this->factory->processes->specs[0]->cwd);
        self::assertSame(['LC_ALL' => 'C'], $this->factory->processes->specs[0]->env, "git's messages are matched in English, whatever the locale");
        self::assertSame([[$cli, self::FORMAT, self::CHECK, self::README]], $this->commands());
        self::assertStringContainsString('Gitignored, skipped: docs', $this->factory->output->fetch());
    }

    #[Test]
    public function withEveryPresentPathIgnoredTheLaneSkips(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->factory->processes->willSucceed(self::README . "\n");

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('no markdown path to format: each is absent or gitignored', $result->summary);
        self::assertSame([], $this->commands(), 'the daemon is never handed an ignored path');
    }

    /** check-ignore exits 128 on its own error; guessing "not ignored" would hide it. */
    #[Test]
    public function aGitProbeThatCannotRunIsACrash(): void
    {
        $this->installDaemon();
        $this->writeReadme();
        $this->factory->processes->willFail(128, "fatal: ../shared: '../shared' is outside repository");

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('git check-ignore could not run (exit 128)', $result->summary);
        self::assertStringContainsString('is outside repository', $this->factory->output->fetch());
        self::assertSame([], $this->commands());
    }

    /**
     * Outside a git work tree there is no ignore list, and the daemon refuses
     * nothing there either, so the lane formats every present path.
     */
    #[Test]
    public function outsideAGitWorkTreeNothingIsIgnored(): void
    {
        $cli = $this->installDaemon();
        $this->writeReadme();
        $this->factory->processes->willFail(128, 'fatal: not a git repository (or any of the parent directories): .git')->willSucceed();

        $result = new MarkdownFormatTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([[$cli, self::FORMAT, self::CHECK, self::README]], $this->commands());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new MarkdownFormatTool();

        self::assertSame('markdownFormat', $tool->name());
        self::assertSame('phpqaci.markdownFormat', $tool->identifier());
    }

    /** `git check-ignore` exits 1 when none of the paths it was given is ignored. */
    private function noneIgnored(): \LTS\PHPQA\Tests\Support\FakeProcessRunner
    {
        return $this->factory->processes->willFail(1);
    }

    private function writeReadme(): void
    {
        $this->factory->project->write(self::README, "# Readme\n");
    }

    private function installDaemon(): string
    {
        return $this->factory->project->write(HooksDaemonCliLocator::CLI, "#!/bin/bash\n");
    }

    private function context(bool $readOnly): ToolContext
    {
        return $this->factory->context($this->factory->builder(readOnly: $readOnly)->build());
    }

    /** @return list<list<string>> the daemon calls, without the git probe */
    private function commands(): array
    {
        return array_values(array_filter(
            array_map(static fn (\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto $spec): array => $spec->command, $this->factory->processes->specs),
            static fn (array $command): bool => 'git' !== $command[0],
        ));
    }
}
