<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\DeploySkills;

use LTS\PHPQA\Tests\Assets\DeploySkills\DeployProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * scripts/deploy-skills.bash runs three of php-qa-ci's own helpers whose failure must not fail
 * the consumer's install. A helper killed by a signal prints nothing, so each warning names how
 * the helper ended rather than pointing at output above it.
 *
 * The helpers are killed by stand-ins for `php` and `bash` that pass every other command through.
 * SIGTERM stands for every signal: a real SIGSEGV would leave a core dump on the host.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DeploySkillsRelaysHelperExitTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string KILLED = 'exit code 143, killed by signal 15 (SIGTERM)';

    private const string BASH = 'bash';

    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('phpqaci-deploy-helper-exit');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    #[Test]
    public function aKilledHelperIsReportedByItsSignalAndTheDeployGoesOn(): void
    {
        $consumer = $this->temp->mkdir('project');
        $this->temp->mkdir('project/src');
        $this->temp->mkdir('project/.git/hooks');
        $this->temp->write('project/composer.json', '{"name": "acme/widget", "type": "project", "config": {"bin-dir": "bin"}}');
        $this->temp->write('project/.claude/hooks-daemon.yaml', "version: '2.0'\nhandlers: {}\n");

        $shims = $this->temp->mkdir('shims');
        $this->shim('php', \PHP_BINARY, '*/bin/rules|*/bin/hooks-daemon-full-qa-blocker');
        $this->shim(self::BASH, $this->find(self::BASH), '*/write-claude-block.bash');

        $path = getenv('PATH');
        self::assertIsString($path);
        $qaciPath = \Safe\realpath(self::REPO_ROOT);
        $result   = DeployProcessRunner::run($qaciPath . '/scripts/deploy-skills.bash', $qaciPath, $consumer, [
            'PATH'       => $shims . ':' . $path,
            'PHP_BINARY' => $shims . '/php',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Could not list the active defences: bin/rules failed with ' . self::KILLED . ' — the block points to vendor/bin/rules instead', $result['output']);
        self::assertStringContainsString('write-claude-block.bash failed with ' . self::KILLED . "\n", $result['output']);
        self::assertStringContainsString('subagent_full_qa_blocker step failed with ' . self::KILLED . ' — continuing deployment', $result['output']);
    }

    /** A stand-in for $name that kills itself with SIGTERM when its first argument matches $kill. */
    private function shim(string $name, string $real, string $kill): void
    {
        $path = $this->temp->write('shims/' . $name, \sprintf(
            "#!%s\ncase \"\${1:-}\" in\n    %s) kill -TERM \$\$ ;;\nesac\nexec %s \"\$@\"\n",
            $this->find(self::BASH),
            $kill,
            escapeshellarg($real),
        ));
        \Safe\chmod($path, 0o755);
    }

    private function find(string $executable): string
    {
        return new ExecutableFinder()->find($executable) ?? throw new RuntimeException($executable . ' is not on PATH');
    }
}
