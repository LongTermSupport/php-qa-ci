<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Config\CoverageDriverEnum;
use LTS\PHPQA\Pipeline\Config\Dto\OpcacheSettingsDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpInvoker::class)]
#[CoversClass(ProcessSpecDto::class)]
#[CoversClass(ProcessResultDto::class)]
#[UsesClass(OpcacheSettingsDto::class)]
#[UsesClass(CoverageDriverEnum::class)]
#[Small]
final class PhpInvokerTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string BIN_TOOL = '/lib/bin/tool';

    private const string DISPLAY_ERRORS = 'display_errors=stderr';

    private const string PROJECT = '/project';

    private const string PHP_BINARY = 'php';

    private const string PHP_AT_OPT = '/opt/php';

    private const string PHPUNIT = '/lib/bin/phpunit';

    private const string MEMORY_2G = 'memory_limit=2G';

    private const array CALLER_ENV = ['FOO' => 'bar'];

    private const array PHPUNIT_ARGS = ['-c', 'x.xml'];

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

        $result = $invoker->withoutXdebug(self::BIN_TOOL, ['--flag', 'value'], self::PROJECT, self::CALLER_ENV);

        self::assertSame('tool ran', $result->output);
        self::assertTrue($result->succeeded());
        $spec = $runner->lastSpec();
        self::assertSame(
            ['/usr/bin/php8.5', '-d', 'memory_limit=4G', '-d', self::DISPLAY_ERRORS, '-f', self::BIN_TOOL, '--', '--flag', 'value'],
            $spec->command,
        );
        self::assertSame(self::PROJECT, $spec->cwd);
        self::assertSame([...self::CALLER_ENV, ...self::XDEBUG_OFF], $spec->env);
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

        $result = $invoker->withXdebug(self::PHPUNIT, self::PHPUNIT_ARGS, self::PROJECT, self::XDEBUG_COVERAGE, streamOutput: false);

        self::assertSame(2, $result->exitCode);
        self::assertFalse($result->succeeded());
        self::assertSame([self::PHP_BINARY, '-d', self::MEMORY_2G, '-d', self::DISPLAY_ERRORS, '-f', self::PHPUNIT, '--', ...self::PHPUNIT_ARGS], $runner->lastSpec()->command);
        self::assertSame(self::XDEBUG_COVERAGE, $runner->lastSpec()->env);
        self::assertFalse($runner->lastSpec()->streamOutput);
    }

    #[Test]
    public function aPcovCoverageRunEnablesPcovOverTheProjectAndSwitchesXdebugOff(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed('');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '2G', $this->varDir->path);

        $invoker->withCoverage(CoverageDriverEnum::Pcov, self::PHPUNIT, self::PHPUNIT_ARGS, self::PROJECT, [...self::CALLER_ENV, ...self::XDEBUG_COVERAGE], streamOutput: false);

        $spec = $runner->lastSpec();
        self::assertSame(
            [
                self::PHP_BINARY, '-d', self::MEMORY_2G, '-d', self::DISPLAY_ERRORS,
                '-d', 'pcov.enabled=1', '-d', 'pcov.directory=/project', '-d', 'pcov.exclude=~^/project/vendor/~',
                '-f', self::PHPUNIT, '--', ...self::PHPUNIT_ARGS,
            ],
            $spec->command,
        );
        // The caller's environment is kept, except that Xdebug never collects alongside pcov.
        self::assertSame([...self::CALLER_ENV, ...self::XDEBUG_OFF], $spec->env);
        self::assertSame(self::PROJECT, $spec->cwd);
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function anXdebugCoverageRunPutsXdebugInCoverageModeWithNoExtraIni(): void
    {
        $runner  = new FakeProcessRunner()->willFail(2, 'boom');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '2G', $this->varDir->path);

        $result = $invoker->withCoverage(CoverageDriverEnum::Xdebug, self::PHPUNIT, [], self::PROJECT, self::XDEBUG_OFF);

        self::assertSame(2, $result->exitCode);
        self::assertSame([self::PHP_BINARY, '-d', self::MEMORY_2G, '-d', self::DISPLAY_ERRORS, '-f', self::PHPUNIT, '--'], $runner->lastSpec()->command);
        self::assertSame(self::XDEBUG_COVERAGE, $runner->lastSpec()->env);
        self::assertTrue($runner->lastSpec()->streamOutput);
    }

    #[Test]
    #[DataProvider('coverageProbeAnswers')]
    public function theCoverageDriverIsProbedFromTheConfiguredBinary(string $answer, CoverageDriverEnum $expected): void
    {
        $runner  = new FakeProcessRunner()->willSucceed($answer);
        $invoker = new PhpInvoker($runner, self::PHP_AT_OPT, '4G', $this->varDir->path);

        self::assertSame($expected, $invoker->coverageDriver());
        self::assertCount(1, $runner->specs);
        self::assertSame(self::PHP_AT_OPT, $runner->specs[0]->command[0]);
        self::assertSame(self::XDEBUG_OFF, $runner->specs[0]->env);
        // pcov counts only when enabled, the same test PHPUnit makes when it picks a driver.
        self::assertStringContainsString('extension_loaded("pcov") && "1" === ini_get("pcov.enabled")', $runner->specs[0]->commandLine());
        self::assertStringContainsString('extension_loaded("xdebug")', $runner->specs[0]->commandLine());
    }

    /** @return iterable<string, array{string, CoverageDriverEnum}> */
    public static function coverageProbeAnswers(): iterable
    {
        yield 'pcov only' => ["1|0\n", CoverageDriverEnum::Pcov];
        yield 'xdebug only' => ["0|1\n", CoverageDriverEnum::Xdebug];
        yield 'both' => ["1|1\n", CoverageDriverEnum::Pcov];
        yield 'neither' => ["0|0\n", CoverageDriverEnum::None];
        yield 'no answer' => ['', CoverageDriverEnum::None];
        yield 'a partial answer' => ['1', CoverageDriverEnum::None];
    }

    #[Test]
    public function versionAndXdebugAreProbedFromTheConfiguredBinary(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed("8.5.10\n")->willSucceed('1');
        $invoker = new PhpInvoker($runner, self::PHP_AT_OPT, '4G', $this->varDir->path);

        self::assertSame(self::PHP_VERSION, $invoker->version());
        self::assertTrue($invoker->hasXdebug());
        self::assertSame([self::PHP_AT_OPT, '-d', self::DISPLAY_ERRORS, '-r', 'echo PHP_VERSION;'], $runner->specs[0]->command);
        self::assertFalse($runner->specs[0]->streamOutput);
        // A host Xdebug in debug mode prints "Could not connect to debugging client" on every
        // CLI call; both probes switch it off so the captured output is the answer alone.
        self::assertSame(self::XDEBUG_OFF, $runner->specs[0]->env);
        self::assertSame(self::XDEBUG_OFF, $runner->specs[1]->env);
    }

    #[Test]
    public function opcacheSettingsAreProbedInOneCallAndParsed(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed("1|0x7FFEBFDF\n");
        $invoker = new PhpInvoker($runner, self::PHP_AT_OPT, '4G', $this->varDir->path);

        $settings = $invoker->opcacheSettings();

        self::assertTrue($settings->loaded);
        self::assertSame('0x7FFEBFDF', $settings->optimizationLevel);
        self::assertCount(1, $runner->specs);
        self::assertSame(self::XDEBUG_OFF, $runner->specs[0]->env);
        self::assertStringContainsString('opcache.optimization_level', $runner->specs[0]->commandLine());
    }

    #[Test]
    public function jitSettingsAreProbedWithXdebugOffAndReturnedTrimmed(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed("1|tracing|64M\n");
        $invoker = new PhpInvoker($runner, self::PHP_AT_OPT, '4G', $this->varDir->path);

        self::assertSame('1|tracing|64M', $invoker->jitSettings());
        self::assertSame(self::XDEBUG_OFF, $runner->specs[0]->env);
        self::assertStringContainsString('opcache.jit_buffer_size', $runner->specs[0]->commandLine());
    }

    #[Test]
    public function aBinaryThatCannotAnswerTheOpcacheProbeReadsAsNotLoaded(): void
    {
        $runner  = new FakeProcessRunner()->willFail(255, 'Segmentation fault');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '4G', $this->varDir->path);

        $settings = $invoker->opcacheSettings();

        self::assertFalse($settings->loaded);
        self::assertSame('', $settings->optimizationLevel);
    }

    #[Test]
    public function iniOverridesBecomeDashDFlagsAfterTheMemoryLimit(): void
    {
        $runner  = new FakeProcessRunner()->willSucceed('');
        $invoker = new PhpInvoker($runner, self::PHP_BINARY, '4G', $this->varDir->path);

        $spec = $invoker->specWithoutXdebug('/x', ['a'], '/p', ini: ['opcache.enable_cli' => '1', 'opcache.opt_debug_level' => '0x20000']);

        self::assertSame(
            [self::PHP_BINARY, '-d', 'memory_limit=4G', '-d', self::DISPLAY_ERRORS, '-d', 'opcache.enable_cli=1', '-d', 'opcache.opt_debug_level=0x20000', '-f', '/x', '--', 'a'],
            $spec->command,
        );
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
        $spec = new ProcessSpecDto([self::PHP_BINARY, '-d', self::DISPLAY_ERRORS, '-r', 'echo 1;', 'plain-arg', '/a/b.c'], '/p');

        self::assertSame("php -d display_errors=stderr -r 'echo 1;' plain-arg /a/b.c", $spec->commandLine());
    }
}
