<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Builds a ToolContext over a throwaway project directory with a fake
 * process runner, so a tool or the pipeline can be driven in a unit test.
 */
final readonly class ContextFactory
{
    public function __construct(
        public TempDir $project,
        public FakeProcessRunner $processes,
        public BufferedOutput $output,
        public BufferedOutput $stdout,
    ) {
    }

    public static function create(): self
    {
        $project = TempDir::create('phpqa-ctx');
        $project->mkdir('src');
        $project->mkdir('tests');
        $project->mkdir('qaConfig');
        $project->write('composer.json', "{\n  \"name\": \"fixture/ctx\",\n  \"type\": \"project\"\n}\n");

        return new self($project, new FakeProcessRunner(), new BufferedOutput(), new BufferedOutput());
    }

    public function paths(): ProjectPathsDto
    {
        $root = $this->project->path;

        return new ProjectPathsDto(
            projectRoot: $root,
            libraryRoot: \dirname(__DIR__, 2),
            binDir: $root . '/vendor/bin',
            srcDir: $root . '/src',
            testsDir: $root . '/tests',
            projectConfigDir: $root . '/qaConfig',
            varDir: $root . '/var/qa',
            cacheDir: $root . '/var/qa/cache',
            pharDir: \dirname(__DIR__, 2) . '/vendor-phar',
            configDefaultsDir: \dirname(__DIR__, 2) . '/configDefaults',
        );
    }

    /** @param array<string, string> $env */
    public function builder(
        array $env = [],
        bool $ci = true,
        bool $readOnly = true,
        bool $aggregate = true,
        bool $jsonOutput = false,
        ?string $singleTool = null,
        ?string $specifiedPath = null,
        bool $xdebug = true,
        PlatformEnum $platform = PlatformEnum::Generic,
    ): QaConfigBuilder {
        return QaConfigBuilder::defaults(
            paths: $this->paths(),
            platform: $platform,
            env: new EnvironmentReader($env),
            phpBinPath: '/usr/bin/php',
            xdebugEnabled: $xdebug,
            halfCpuThreads: 2,
            ci: $ci,
            readOnly: $readOnly,
            aggregate: $aggregate,
            jsonOutput: $jsonOutput,
            singleTool: $singleTool,
            specifiedPath: $specifiedPath,
        );
    }

    public function context(?QaConfigDto $config = null): ToolContext
    {
        $config ??= $this->builder()->build();
        $paths = $config->paths;

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $this->processes,
            php: new PhpInvoker($this->processes, $config->phpBinPath, $config->memoryLimit, $paths->varDir),
            logs: new LogArchiver($this->output),
            output: $this->output,
            stdout: $this->stdout,
        );
    }
}
