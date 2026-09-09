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
    public function withoutXdebugBuildsTheNoXdebugIniOnceAndPassesMemoryLimitAndArgs(): void
    {
        $iniA = $this->varDir->write('a.ini', "memory_limit=1G\n");
        $iniX = $this->varDir->write('xdebug.ini', "zend_extension=xdebug\n");

        $runner = new FakeProcessRunner()
            ->willSucceed(self::PHP_VERSION)
            ->willSucceed($iniA . "\n" . $iniX . ",\n")
            ->willSucceed('tool ran')
            ->willSucceed(self::PHP_VERSION)
            ->willSucceed('again')
        ;
        $invoker = new PhpInvoker($runner, '/usr/bin/php8.5', '4G', $this->varDir->path);

        $result = $invoker->withoutXdebug(self::BIN_TOOL, ['--flag', 'value'], self::PROJECT, ['FOO' => 'bar']);

        self::assertSame('tool ran', $result->output);
        self::assertTrue($result->succeeded());
        $spec = $runner->lastSpec();
        self::assertSame(
            ['/usr/bin/php8.5', '-n', '-c', $this->varDir->path . '/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', self::BIN_TOOL, '--', '--flag', 'value'],
            $spec->command,
        );
        self::assertSame(self::PROJECT, $spec->cwd);
        self::assertSame(['FOO' => 'bar'], $spec->env);
        self::assertSame("memory_limit=1G\n\n", $this->varDir->read('phpqa-no-xdebug.8.5.10.ini'));

        // Second invocation: the ini exists, so only the version probe and the tool run.
        $invoker->withoutXdebug(self::BIN_TOOL, [], self::PROJECT);
        self::assertCount(5, $runner->specs);
    }

    #[Test]
    public function withXdebugUsesTheFullIniSetAndNoDashN(): void
    {
        $runner  = new FakeProcessRunner()->willFail(2, 'boom');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '2G', $this->varDir->path);

        $result = $invoker->withXdebug('/lib/bin/phpunit', ['-c', 'x.xml'], self::PROJECT, ['XDEBUG_MODE' => 'coverage'], streamOutput: false);

        self::assertSame(2, $result->exitCode);
        self::assertFalse($result->succeeded());
        self::assertSame([self::PHP_BINARY, '-d', 'memory_limit=2G', '-f', '/lib/bin/phpunit', '--', '-c', 'x.xml'], $runner->lastSpec()->command);
        self::assertSame(['XDEBUG_MODE' => 'coverage'], $runner->lastSpec()->env);
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
    }

    #[Test]
    public function lowPriorityIsCarriedOnTheSpec(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed(self::PHP_VERSION)->willSucceed('');
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
