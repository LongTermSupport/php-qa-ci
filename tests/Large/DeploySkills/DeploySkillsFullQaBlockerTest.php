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

    private TempDir $temp;

    private string $consumer;

    protected function setUp(): void
    {
        $this->temp     = TempDir::create('phpqaci-deploy-blocker');
        $this->consumer = $this->temp->mkdir('project');
        $this->temp->mkdir('project/src');
        $this->temp->mkdir('project/.git/hooks');
        $this->temp->write('project/composer.json', '{"name": "acme/widget", "type": "project", "config": {"bin-dir": "bin"}}');
        $this->temp->write('project/' . self::CONFIG, self::COMMENTED_CONFIG);
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
        $this->installDaemon('3.66.0');

        self::assertStringContainsString('hooks-daemon 3.66.0 predates subagent_full_qa_blocker', $this->deploy());
        self::assertSame(self::COMMENTED_CONFIG, $this->config());
    }

    #[Test]
    public function aConfigWithNoDaemonInstallBesideItIsLeftAlone(): void
    {
        self::assertStringContainsString('no hooks-daemon install with a readable version', $this->deploy());
        self::assertSame(self::COMMENTED_CONFIG, $this->config());
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
        return $this->temp->read('project/' . self::CONFIG);
    }
}
