<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance;
use LTS\PHPQA\Pipeline\Lane\RectorTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RectorTool::class)]
#[UsesClass(ContextFactory::class)]
#[UsesClass(ReadOnlyGuidance::class)]
#[Small]
final class RectorToolTest extends TestCase
{
    private const string ADVISORY = 'Rector ran in WRITABLE mode and may have rewritten files. DO NOT FIGHT IT.';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write('var/qa/phpqa-no-xdebug.8.5.10.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aReadOnlyRunMakesThreeDryRunPassesWithTheExpectedArgv(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->context(readOnly: true);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(3, $this->rectorSpecs());
        self::assertSame($this->expectedArgs($context, 'rector-safe.php', true, ...$context->config->pathsToCheck), $this->toolArgs(0));
        self::assertSame($this->expectedArgs($context, 'rector-phpunit.php', true, $context->config->paths->testsDir), $this->toolArgs(1));
        self::assertSame($this->expectedArgs($context, 'rector-php85.php', true, ...$context->config->pathsToCheck), $this->toolArgs(2));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString("Running Rector ('Safe') in read-only check mode", $printed);
        self::assertStringContainsString('Running PHPUnit Rector on ' . $context->config->paths->testsDir, $printed);
        self::assertStringContainsString("Running Rector ('PHP 8.5') in read-only check mode", $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed);
    }

    #[Test]
    public function aWritableRunOmitsDryRunAndPrintsTheAdvisory(): void
    {
        $this->queuePasses(0, 0, 0);
        $context = $this->context(readOnly: false);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->expectedArgs($context, 'rector-safe.php', false, ...$context->config->pathsToCheck), $this->toolArgs(0));
        foreach ($this->rectorSpecs() as $spec) {
            self::assertNotContains('--dry-run', $spec->command);
        }

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString("Running Rector ('Safe')", $printed);
        self::assertStringContainsString(self::ADVISORY, $printed);
        self::assertStringContainsString('Roll with Rector. It is part of the gate, not an obstacle to it.', $printed);
    }

    #[Test]
    public function everyPassRunsThePharFromTheProjectRootWithTheIgnorePathsEnv(): void
    {
        $this->queuePasses(0, 0, 0);
        $config  = $this->factory->builder(readOnly: true)->withIgnoredPaths('legacy', 'generated')->build();
        $context = $this->factory->context($config);

        new RectorTool()->run($context);

        foreach ($this->rectorSpecs() as $spec) {
            self::assertSame($config->paths->projectRoot, $spec->cwd);
            self::assertSame(['rectorIgnorePaths' => "legacy\ngenerated"], $spec->env);
            self::assertContains($config->paths->pharDir . '/rector.phar', $spec->command);
        }
    }

    #[Test]
    public function withNoIgnoredPathsTheEnvIsAnEmptyString(): void
    {
        $this->queuePasses(0, 0, 0);

        new RectorTool()->run($this->context(readOnly: true));

        self::assertSame(['rectorIgnorePaths' => ''], array_last($this->rectorSpecs())?->env);
    }

    #[Test]
    public function aProjectRectorReplacesThePhp85Pass(): void
    {
        $this->queuePasses(0, 0, 0, 0);
        $rootConfig     = $this->factory->project->write('rector.php', "<?php\n");
        $qaConfigConfig = $this->factory->project->write('qaConfig/rector.php', "<?php\n");
        $context        = $this->context(readOnly: true);

        $result = new RectorTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(4, $this->rectorSpecs());
        self::assertSame($this->expectedArgsForConfig($context, $rootConfig, true, ...$context->config->pathsToCheck), $this->toolArgs(2));
        self::assertSame($this->expectedArgsForConfig($context, $qaConfigConfig, true, ...$context->config->pathsToCheck), $this->toolArgs(3));

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Running Project Specific Rector as configured in ' . $rootConfig, $printed);
        self::assertStringContainsString('Running Project Specific Rector as configured in ' . $qaConfigConfig, $printed);
        self::assertStringContainsString('Skipping standard PHP 8.5 Rector as we assume its handled in project rector', $printed);
        self::assertStringNotContainsString('rector-php85.php', implode("\n", $this->factory->processes->commandLines()));
    }

    #[Test]
    public function aReadOnlyPendingChangeFailsWithTheRemediationAndStopsAtThatPass(): void
    {
        $this->queuePasses(0, 2);

        $result  = new RectorTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(2, $this->rectorSpecs(), 'the PHP 8.5 pass never runs');
        self::assertStringContainsString("Rector ('PHPUnit'): pending changes in a READ-ONLY run", $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t rector', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed);
    }

    #[Test]
    public function aReadOnlyGenuineErrorCrashes(): void
    {
        $this->queuePasses(1);

        $result  = new RectorTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertCount(1, $this->rectorSpecs());
        self::assertStringContainsString("Rector ('Safe') failed with exit code 1 (a genuine error, not a pending-change diff)", $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
    }

    #[Test]
    public function aWritableNonZeroExitFailsSoTheRunnerCanRetry(): void
    {
        $this->queuePasses(0, 0, 2);

        $result  = new RectorTool()->run($this->context(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("Rector ('PHP 8.5') failed (exit 2)", $result->summary);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
        self::assertStringNotContainsString(self::ADVISORY, $printed, 'the advisory is only printed after every pass succeeded');
    }

    #[Test]
    public function aMissingPharCrashesBeforeRunningAnything(): void
    {
        $paths   = $this->factory->paths();
        $noPhars = new ProjectPathsDto(
            projectRoot: $paths->projectRoot,
            libraryRoot: $paths->libraryRoot,
            binDir: $paths->binDir,
            srcDir: $paths->srcDir,
            testsDir: $paths->testsDir,
            projectConfigDir: $paths->projectConfigDir,
            varDir: $paths->varDir,
            cacheDir: $paths->cacheDir,
            pharDir: $this->factory->project->mkdir('no-phars'),
            configDefaultsDir: $paths->configDefaultsDir,
        );
        $config = QaConfigBuilder::defaults(
            paths: $noPhars,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: '/usr/bin/php',
            xdebugEnabled: true,
            halfCpuThreads: 2,
            ci: true,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            singleTool: null,
            specifiedPath: null,
        )->build();

        $result  = new RectorTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame([], $this->rectorSpecs());
        self::assertStringContainsString('ERROR: Rector PHAR not found at ' . $noPhars->pharDir . '/rector.phar', $printed);
        self::assertStringContainsString('scripts/build-rector-phar.bash', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new RectorTool();

        self::assertSame('rector', $tool->name());
        self::assertSame('phpqaci.rector', $tool->identifier());
    }

    /** Queue one Rector pass per exit code, each preceded by the PHP version probe the invoker makes. */
    private function queuePasses(int ...$exitCodes): void
    {
        foreach ($exitCodes as $exitCode) {
            $this->factory->processes->willSucceed('8.5.10');
            if (0 === $exitCode) {
                $this->factory->processes->willSucceed();
            } else {
                $this->factory->processes->willFail($exitCode);
            }
        }
    }

    /** @return list<ProcessSpecDto> only the Rector invocations, without the invoker's version probes */
    private function rectorSpecs(): array
    {
        return array_values(array_filter(
            $this->factory->processes->specs,
            static fn (ProcessSpecDto $spec): bool => \in_array('-f', $spec->command, true),
        ));
    }

    private function context(bool $readOnly): ToolContext
    {
        return $this->factory->context($this->factory->builder(readOnly: $readOnly)->build());
    }

    /** @return list<string> the argv after the `--` separator, i.e. what Rector itself sees */
    private function toolArgs(int $index): array
    {
        $spec      = $this->rectorSpecs()[$index];
        $separator = array_search('--', $spec->command, true);
        self::assertIsInt($separator);

        return \array_slice($spec->command, $separator + 1);
    }

    /** @return list<string> */
    private function expectedArgs(ToolContext $context, string $shippedConfig, bool $dryRun, string ...$paths): array
    {
        return $this->expectedArgsForConfig($context, $context->configPath($shippedConfig), $dryRun, ...$paths);
    }

    /** @return list<string> */
    private function expectedArgsForConfig(ToolContext $context, string $configFile, bool $dryRun, string ...$paths): array
    {
        $args = [
            'process',
            '--autoload-file',
            $context->config->paths->projectRoot . '/vendor/autoload.php',
            '--config',
            $configFile,
            '--clear-cache',
        ];
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        return [...$args, ...array_values($paths)];
    }
}
