<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpInvoker::class)]
#[CoversClass(ProcessSpecDto::class)]
#[CoversClass(ProcessResultDto::class)]
#[Small]
final class PhpInvokerTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string BIN_TOOL = '/lib/bin/tool';

    private const string PROJECT = '/project';

    private const string PHP_BINARY = 'php';

    private const array XDEBUG_OFF = ['XDEBUG_MODE' => 'off'];

    private const array XDEBUG_COVERAGE = ['XDEBUG_MODE' => 'coverage'];

    private TempDir $varDir;

    protected function setUp(): void
    {
        $this->varDir = TempDir::create('phpqa-invoker');
    }

    protected function tearDown(): void
    {
        $this->varDir->remove();
    }

    #[Test]
    public function withoutXdebugSwitchesXdebugOffThroughTheEnvironmentAndPassesMemoryLimitAndArgs(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed('tool ran');
        $invoker = new PhpInvoker($runner, '/usr/bin/php8.5', '4G', $this->varDir->path);

        $result = $invoker->withoutXdebug(self::BIN_TOOL, ['--flag', 'value'], self::PROJECT, ['FOO' => 'bar']);

        self::assertSame('tool ran', $result->output);
        self::assertTrue($result->succeeded());
        $spec = $runner->lastSpec();
        self::assertSame(
            ['/usr/bin/php8.5', '-d', 'memory_limit=4G', '-f', self::BIN_TOOL, '--', '--flag', 'value'],
            $spec->command,
        );
        self::assertSame(self::PROJECT, $spec->cwd);
        self::assertSame(['FOO' => 'bar', ...self::XDEBUG_OFF], $spec->env);
        // No probe, no generated ini: the tool run is the only process.
        self::assertCount(1, $runner->specs);
        self::assertSame([], \Safe\glob($this->varDir->path . '/*.ini'));
    }

    #[Test]
    public function withoutXdebugOverridesAnXdebugModeTheCallerPassed(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed('');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '4G', $this->varDir->path);

        $spec = $invoker->specWithoutXdebug('/x', [], '/p', self::XDEBUG_COVERAGE);

        self::assertSame(self::XDEBUG_OFF, $spec->env);
    }

    #[Test]
    public function withXdebugUsesTheFullIniSetAndNoDashN(): void
    {
        $runner  = new FakeProcessRunner()->willFail(2, 'boom');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '2G', $this->varDir->path);

        $result = $invoker->withXdebug('/lib/bin/phpunit', ['-c', 'x.xml'], self::PROJECT, self::XDEBUG_COVERAGE, streamOutput: false);

        self::assertSame(2, $result->exitCode);
        self::assertFalse($result->succeeded());
        self::assertSame([self::PHP_BINARY, '-d', 'memory_limit=2G', '-f', '/lib/bin/phpunit', '--', '-c', 'x.xml'], $runner->lastSpec()->command);
        self::assertSame(self::XDEBUG_COVERAGE, $runner->lastSpec()->env);
        self::assertFalse($runner->lastSpec()->streamOutput);
    }

    #[Test]
    public function versionAndXdebugAreProbedFromTheConfiguredBinary(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed("8.5.10\n")->willSucceed('1');
        $invoker = new PhpInvoker($runner, '/opt/php', '4G', $this->varDir->path);

        self::assertSame(self::PHP_VERSION, $invoker->version());
        self::assertTrue($invoker->hasXdebug());
        self::assertSame(['/opt/php', '-r', 'echo PHP_VERSION;'], $runner->specs[0]->command);
        self::assertFalse($runner->specs[0]->streamOutput);
        // A host Xdebug in debug mode prints "Could not connect to debugging client" on every
        // CLI call; both probes switch it off so the captured output is the answer alone.
        self::assertSame(self::XDEBUG_OFF, $runner->specs[0]->env);
        self::assertSame(self::XDEBUG_OFF, $runner->specs[1]->env);
    }

    #[Test]
    public function lowPriorityIsCarriedOnTheSpec(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed('');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '4G', $this->varDir->path);

        $spec = $invoker->specWithoutXdebug('/x', [], '/p', lowPriority: true);

        self::assertTrue($spec->lowPriority);
    }

    #[Test]
    public function theCommandLineQuotesOnlyWhatNeedsQuoting(): void
    {
        $spec = new ProcessSpecDto([self::PHP_BINARY, '-r', 'echo 1;', 'plain-arg', '/a/b.c'], '/p');

        self::assertSame("php -r 'echo 1;' plain-arg /a/b.c", $spec->commandLine());
    }
}
