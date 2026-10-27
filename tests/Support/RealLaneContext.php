<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A ToolContext over a probe project that runs real processes with this library's shipped
 * phars and config defaults, for a Large test that drives a lane end to end. ContextFactory
 * is its fake-process twin.
 */
final readonly class RealLaneContext
{
    /** @param list<string> $ignoredPaths the project's withIgnoredPaths() entries */
    public static function over(string $root, bool $readOnly, BufferedOutput $output, ?string $specifiedPath = null, array $ignoredPaths = []): ToolContext
    {
        $library = \dirname(__DIR__, 2);
        $paths   = new ProjectPathsDto(
            projectRoot: $root,
            libraryRoot: $library,
            binDir: $root . '/vendor/bin',
            srcDir: $root . '/src',
            testsDir: $root . '/tests',
            projectConfigDir: $root . '/qaConfig',
            varDir: $root . '/var/qa',
            cacheDir: $root . '/var/qa/cache',
            pharDir: $library . '/vendor-phar',
            configDefaultsDir: $library . '/configDefaults',
        );
        $config = QaConfigBuilder::defaults(
            paths: $paths,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: \PHP_BINARY,
            xdebugEnabled: false,
            halfCpuThreads: 1,
            ci: false,
            readOnly: $readOnly,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: $specifiedPath,
        )->withIgnoredPaths(...$ignoredPaths)->build();
        $processes = new SymfonyProcessRunner($output);

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $processes,
            php: new PhpInvoker($processes, $config->phpBinPath, $config->memoryLimit, $paths->varDir),
            logs: new LogArchiver($output),
            output: $output,
            stdout: $output,
        );
    }
}
