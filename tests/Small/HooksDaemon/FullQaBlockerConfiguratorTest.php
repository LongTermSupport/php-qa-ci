<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\ConfigureOutcomeEnum;
use LTS\PHPQA\HooksDaemon\Dto\YamlEditResultDto;
use LTS\PHPQA\HooksDaemon\FullQaBlockerBlockRenderer;
use LTS\PHPQA\HooksDaemon\FullQaBlockerConfigurator;
use LTS\PHPQA\HooksDaemon\FullQaBlockerYamlEditor;
use LTS\PHPQA\HooksDaemon\HooksDaemonVersionReader;
use LTS\PHPQA\HooksDaemon\YamlLines;
use LTS\PHPQA\Pipeline\Config\ComposerBinDirReader;
use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\FileCapturingProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(FullQaBlockerConfigurator::class)]
#[UsesClass(FullQaBlockerYamlEditor::class)]
#[UsesClass(YamlLines::class)]
#[UsesClass(FullQaBlockerBlockRenderer::class)]
#[UsesClass(HooksDaemonVersionReader::class)]
#[UsesClass(ComposerBinDirReader::class)]
#[UsesClass(ProjectLayoutException::class)]
#[UsesClass(YamlEditResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[Small]
final class FullQaBlockerConfiguratorTest extends TestCase
{
    private const string CONFIG = '.claude/hooks-daemon.yaml';

    private const string CLI = '.claude/hooks-daemon/bin/hooks-daemon';

    private const string ORIGINAL = "# Project daemon config.\nhandlers:\n  pre_tool_use:\n    sed_blocker:\n      enabled: true\n";

    private TempDir $project;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqaci-full-qa-blocker');
        $this->project->write('composer.json', '{"config": {"bin-dir": "bin"}}');
        $this->project->write(self::CONFIG, self::ORIGINAL);
        $this->project->write(self::CLI, "#!/bin/bash\n");
        $this->installDaemon('3.67.0');
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theBlockIsWrittenOnlyAfterTheDaemonValidatedIt(): void
    {
        \Safe\chmod($this->configPath(), 0o640);
        $expected  = new FullQaBlockerYamlEditor()->apply(self::ORIGINAL, 'bin')->yaml;
        $validator = $this->validatorSeeing(0);

        $outcome = $this->configurator($validator)->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::Written, $outcome);
        self::assertSame($expected, $this->project->read(self::CONFIG));
        self::assertSame($expected, $validator->captured, 'the daemon must have validated exactly what was written');
        $spec = $validator->lastSpec();
        self::assertSame(
            [$this->project->path . '/' . self::CLI, 'config-validate', $this->configPath() . FullQaBlockerConfigurator::CANDIDATE_SUFFIX],
            $spec->command,
        );
        self::assertSame($this->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput);
        self::assertSame(0o640, \Safe\fileperms($this->configPath()) & 0o777, 'the config keeps its permissions');
        self::assertSame(['hooks-daemon.yaml'], $this->project->files('.claude'), 'no candidate file is left behind');
        self::assertStringContainsString('subagent_full_qa_blocker configured', $this->output->fetch());
    }

    #[Test]
    public function aWrittenBlockTellsTheUserToRestartTheDaemon(): void
    {
        $this->configurator($this->validatorSeeing(0))->configure($this->project->path, $this->configPath());

        self::assertStringContainsString(self::CLI . ' restart', $this->output->fetch());
    }

    #[Test]
    public function aRejectedCandidateLeavesTheConfigUntouched(): void
    {
        $outcome = $this->configurator($this->validatorSeeing(1, "unknown key: full_qa_patterns\n"))
            ->configure($this->project->path, $this->configPath())
        ;

        self::assertSame(ConfigureOutcomeEnum::Rejected, $outcome);
        self::assertSame(self::ORIGINAL, $this->project->read(self::CONFIG));
        self::assertSame(['hooks-daemon.yaml'], $this->project->files('.claude'));
        $printed = $this->output->fetch();
        self::assertStringContainsString('exit 1', $printed);
        self::assertStringContainsString('    unknown key: full_qa_patterns', $printed);
    }

    #[Test]
    public function aDaemonOlderThanTheHandlerIsLeftAlone(): void
    {
        $this->installDaemon('3.66.9');

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::DaemonTooOld, $outcome);
        self::assertSame(self::ORIGINAL, $this->project->read(self::CONFIG));
        $printed = $this->output->fetch();
        self::assertStringContainsString('3.66.9', $printed);
        self::assertStringContainsString(FullQaBlockerConfigurator::MINIMUM_DAEMON_VERSION, $printed);
    }

    #[Test]
    public function aDaemonWithNoReadableVersionIsLeftAlone(): void
    {
        \Safe\unlink($this->project->path . '/.claude/hooks-daemon/' . HooksDaemonVersionReader::VERSION_FILE);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::NoDaemonInstall, $outcome);
        self::assertSame(self::ORIGINAL, $this->project->read(self::CONFIG));
        self::assertStringContainsString('no hooks-daemon install', $this->output->fetch());
    }

    #[Test]
    public function aMissingConfigIsNothingToConfigure(): void
    {
        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->project->path . '/nowhere.yaml');

        self::assertSame(ConfigureOutcomeEnum::NoDaemonConfig, $outcome);
        self::assertStringContainsString('nowhere.yaml', $this->output->fetch());
    }

    #[Test]
    public function aConfiguredProjectRunsNothing(): void
    {
        $this->project->write(self::CONFIG, new FullQaBlockerYamlEditor()->apply(self::ORIGINAL, 'bin')->yaml);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::Unchanged, $outcome);
        self::assertStringContainsString('already configured', $this->output->fetch());
    }

    #[Test]
    public function aProjectThatDisabledTheHandlerKeepsItDisabled(): void
    {
        $disabled = "handlers:\n  pre_tool_use:\n    subagent_full_qa_blocker:\n      enabled: false\n";
        $this->project->write(self::CONFIG, $disabled);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::DisabledByProject, $outcome);
        self::assertSame($disabled, $this->project->read(self::CONFIG));
        self::assertStringContainsString('disabled', $this->output->fetch());
    }

    #[Test]
    public function aProjectsOwnBlockIsLeftWithAPointerToTheRecommendedOne(): void
    {
        $own = "handlers:\n  pre_tool_use:\n    subagent_full_qa_blocker:\n      enabled: true\n";
        $this->project->write(self::CONFIG, $own);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::ProjectOwned, $outcome);
        self::assertSame($own, $this->project->read(self::CONFIG));
        self::assertStringContainsString('docs/hooks-daemon-full-qa-blocker.md', $this->output->fetch());
    }

    #[Test]
    public function aLayoutTheEditorCannotReadIsLeftAlone(): void
    {
        $flow = "handlers: {pre_tool_use: {}}\n";
        $this->project->write(self::CONFIG, $flow);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::Unsupported, $outcome);
        self::assertSame($flow, $this->project->read(self::CONFIG));
        self::assertStringContainsString('by hand', $this->output->fetch());
    }

    #[Test]
    public function withoutTheDaemonCliNothingIsWrittenUnvalidated(): void
    {
        \Safe\unlink($this->project->path . '/' . self::CLI);

        $outcome = $this->configurator(new FakeProcessRunner())->configure($this->project->path, $this->configPath());

        self::assertSame(ConfigureOutcomeEnum::CannotValidate, $outcome);
        self::assertSame(self::ORIGINAL, $this->project->read(self::CONFIG));
        self::assertStringContainsString('config-validate', $this->output->fetch());
    }

    #[Test]
    public function anyDecisionIsASuccessfulRun(): void
    {
        $this->installDaemon('3.0.0');

        self::assertSame(0, $this->configurator(new FakeProcessRunner())->run($this->project->path, $this->configPath()));
    }

    /** Composer must never fail on this step, but a broken attempt still says so in its exit code. */
    #[Test]
    public function aBrokenAttemptExitsOneAndSaysWhy(): void
    {
        \Safe\unlink($this->project->path . '/composer.json');

        self::assertSame(1, $this->configurator(new FakeProcessRunner())->run($this->project->path, $this->configPath()));
        self::assertStringContainsString('subagent_full_qa_blocker not configured: No readable composer.json', $this->output->fetch());
        self::assertSame(self::ORIGINAL, $this->project->read(self::CONFIG));
    }

    private function configurator(ProcessRunnerInterface $processes): FullQaBlockerConfigurator
    {
        return new FullQaBlockerConfigurator($processes, $this->output);
    }

    private function installDaemon(string $version): void
    {
        $this->project->write('.claude/hooks-daemon/' . HooksDaemonVersionReader::VERSION_FILE, \sprintf("__version__ = \"%s\"\n", $version));
    }

    private function configPath(): string
    {
        return $this->project->path . '/' . self::CONFIG;
    }

    /** A config-validate stand-in that records the candidate it was shown. */
    private function validatorSeeing(int $exitCode, string $output = ''): FileCapturingProcessRunner
    {
        return new FileCapturingProcessRunner($exitCode, $output);
    }
}
