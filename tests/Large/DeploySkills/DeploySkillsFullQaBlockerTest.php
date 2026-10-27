<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\DeploySkills;

use LTS\PHPQA\Tests\Assets\DeploySkills\DeployProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * scripts/deploy-skills.bash, run as composer runs it, declares the hooks
 * daemon's subagent_full_qa_blocker in a consumer whose daemon has the
 * handler, and leaves every other consumer's config alone.
 *
 * The daemon is a stand-in: its version module, and a CLI whose
 * `config-validate` accepts any file that exists. The real daemon's acceptance
 * of the block is proven against an installed daemon, not here.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DeploySkillsFullQaBlockerTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string CONFIG = '.claude/hooks-daemon.yaml';

    private const string PROJECT_CONFIG = 'project/' . self::CONFIG;

    private const string OLD_DAEMON = '3.66.0';

    private const string COMMENTED_CONFIG = <<<'YAML'
        version: '2.0'
        # Every comment in this file must survive the deploy.
        handlers:
          pre_tool_use:
            # Destructive git stays blocked.
            destructive_git:
              enabled: true
          stop:
            auto_continue_stop:
              enabled: true

        YAML;

    private const string COMPLIANT_CONFIG = <<<'YAML'
        # Why each setting is the way it is.
        handlers:
            pre_tool_use:
                git_stash:   # no stashes
                    enabled: true
                    options:
                        mode: deny   # owner decision
                plan_time_estimates:
                    enabled: true
                validate_instruction_content:
                    enabled: true
                markdown_organization:
                    enabled: true

        YAML;

    private TempDir $temp;

    private string $consumer;

    protected function setUp(): void
    {
        $this->temp     = TempDir::create('phpqaci-deploy-blocker');
        $this->consumer = $this->temp->mkdir('project');
        $this->temp->mkdir('project/src');
        $this->temp->mkdir('project/.git/hooks');
        $this->temp->write('project/composer.json', '{"name": "acme/widget", "type": "project", "config": {"bin-dir": "bin"}}');
        $this->temp->write(self::PROJECT_CONFIG, self::COMMENTED_CONFIG);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    #[Test]
    public function aDaemonThatHasTheHandlerGetsItDeclaredOnce(): void
    {
        $this->installDaemon('3.67.0');

        $first = $this->deploy();
        self::assertStringContainsString('subagent_full_qa_blocker configured', $first);

        $config = $this->config();
        self::assertStringContainsString("    subagent_full_qa_blocker:\n      # Managed by php-qa-ci", $config);
        self::assertStringContainsString('- "bin/qa -t <tool> -p <file or directory you changed>"', $config);
        foreach (['# Every comment in this file must survive the deploy.', '    # Destructive git stays blocked.', "  stop:\n    auto_continue_stop:"] as $kept) {
            self::assertStringContainsString($kept, $config);
        }

        $second = $this->deploy();
        self::assertStringContainsString('subagent_full_qa_blocker already configured', $second);
        self::assertSame($config, $this->config(), 'a second composer install must not touch the config');
    }

    #[Test]
    public function aDaemonOlderThanTheHandlerIsLeftAlone(): void
    {
        $this->installDaemon(self::OLD_DAEMON);

        self::assertStringContainsString('hooks-daemon 3.66.0 predates subagent_full_qa_blocker', $this->deploy());
        $this->assertOnlyRequiredHandlersAdded();
    }

    #[Test]
    public function aConfigWithNoDaemonInstallBesideItIsLeftAlone(): void
    {
        self::assertStringContainsString('no hooks-daemon install with a readable version', $this->deploy());
        $this->assertOnlyRequiredHandlersAdded();
    }

    #[Test]
    public function aConfigWhoseRequiredHandlersAreRightIsNotTouchedByTheDeploy(): void
    {
        $this->temp->write(self::PROJECT_CONFIG, self::COMPLIANT_CONFIG);
        $this->installDaemon(self::OLD_DAEMON);

        self::assertStringContainsString('All required handlers already configured correctly', $this->deploy());
        self::assertSame(self::COMPLIANT_CONFIG, $this->config());
    }

    #[Test]
    public function aWrongGitStashModeIsTheOnlyLineTheDeployChanges(): void
    {
        $this->temp->write(self::PROJECT_CONFIG, str_replace('mode: deny', 'mode: warn', self::COMPLIANT_CONFIG));
        $this->installDaemon(self::OLD_DAEMON);

        self::assertStringContainsString('Updated git_stash.options.mode: warn → deny', $this->deploy());
        self::assertSame(self::COMPLIANT_CONFIG, $this->config());
    }

    #[Test]
    public function aLayoutThatCannotBeEditedInPlaceIsRefusedAndLeftAsItWas(): void
    {
        $flow = "# Kept.\nhandlers:\n  pre_tool_use:\n    git_stash: {enabled: true, options: {mode: warn}}\n";
        $this->temp->write(self::PROJECT_CONFIG, $flow);
        $this->installDaemon(self::OLD_DAEMON);

        $output = $this->deploy();

        self::assertStringContainsString('left untouched', $output);
        self::assertStringContainsString('handlers.pre_tool_use.git_stash.options.mode: deny', $output);
        self::assertSame($flow, $this->config());
    }

    private function assertOnlyRequiredHandlersAdded(): void
    {
        $config = $this->config();
        foreach (explode("\n", self::COMMENTED_CONFIG) as $line) {
            if ('' !== $line) {
                self::assertStringContainsString($line . "\n", $config);
            }
        }

        self::assertStringContainsString("    git_stash:\n      enabled: true\n      options:\n        mode: deny\n", $config);
        self::assertStringNotContainsString('subagent_full_qa_blocker:', $config);
    }

    private function installDaemon(string $version): void
    {
        $this->temp->write('project/.claude/hooks-daemon/src/claude_code_hooks_daemon/version.py', \sprintf("__version__ = \"%s\"\n", $version));
        $cli = $this->temp->write(
            'project/.claude/hooks-daemon/bin/hooks-daemon',
            "#!/usr/bin/env bash\nif [[ \"\$1\" == config-validate && -f \"\$2\" ]]; then exit 0; fi\nexit 1\n",
        );
        \Safe\chmod($cli, 0o755);
    }

    private function deploy(): string
    {
        $qaciPath = \Safe\realpath(self::REPO_ROOT);
        $result   = DeployProcessRunner::run($qaciPath . '/scripts/deploy-skills.bash', $qaciPath, $this->consumer);
        self::assertSame(0, $result['exit'], $result['output']);

        return $result['output'];
    }

    private function config(): string
    {
        return $this->temp->read(self::PROJECT_CONFIG);
    }
}
