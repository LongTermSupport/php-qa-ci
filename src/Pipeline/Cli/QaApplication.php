<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformDetector;
use LTS\PHPQA\Pipeline\Config\ProjectConfigLoader;
use LTS\PHPQA\Pipeline\Config\ProjectPathsResolver;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Runner\AggregateReport;
use LTS\PHPQA\Pipeline\Runner\ConsoleRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\DirectoryPreparer;
use LTS\PHPQA\Pipeline\Runner\HookRunner;
use LTS\PHPQA\Pipeline\Runner\NonInteractiveRetryPrompt;
use LTS\PHPQA\Pipeline\Runner\PharToolsVerifier;
use LTS\PHPQA\Pipeline\Runner\Pipeline;
use LTS\PHPQA\Pipeline\Runner\ShippedToolLocator;
use LTS\PHPQA\Pipeline\Runner\ToolExecutor;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/**
 * The composition root behind bin/qa: parses the command line, resolves the
 * environment and project, builds the configuration (defaults, then the
 * project's qa.php), wires the services, and runs the pipeline.
 *
 * All decoration goes to stdout, except in --json mode where it goes to
 * stderr so stdout carries only the structured output.
 *
 * @internal
 */
final readonly class QaApplication
{
    private const string RULE = '===========================================';

    /**
     * @param list<string>          $argv         the arguments after the script name
     * @param array<string, string> $env
     * @param resource              $stdoutStream
     * @param resource              $stderrStream
     * @param resource              $stdinStream
     */
    public function __construct(
        private array $argv,
        private array $env,
        private string $projectRoot,
        private string $libraryRoot,
        private mixed $stdoutStream,
        private mixed $stderrStream,
        private mixed $stdinStream,
        private bool $stdinIsTty,
        private bool $stdoutIsTty,
    ) {
    }

    public function run(): int
    {
        $json       = \in_array('--json', $this->argv, true);
        $stdout     = new StreamOutput($this->stdoutStream);
        $decoration = $json ? new StreamOutput($this->stderrStream) : $stdout;

        $tools    = PipelineBuilder::defaults()->build();
        $registry = $tools->registry;
        $env      = new EnvironmentReader($this->env);
        $parser   = new ArgumentsParser($registry, 'vendor/bin');

        try {
            $request = $parser->parse(...$this->argv);
            $paths   = new ProjectPathsResolver()->resolve($this->projectRoot, $this->libraryRoot);
            $path    = null === $request->path ? null : new PathNormaliser()->normalise($request->path, $paths->projectRoot);
        } catch (UsageException $usageException) {
            $stderr = new StreamOutput($this->stderrStream);
            if ('' !== $usageException->getMessage()) {
                $stderr->writeln($usageException->getMessage());
            }

            if ($usageException->showUsage) {
                $stderr->write($parser->usage());
            }

            return 1;
        }

        $decoration->writeln('');
        $decoration->writeln(self::RULE);
        $decoration->writeln(\sprintf('%s qa %s', \Safe\gethostname(), implode(' ', $this->argv)));
        $decoration->writeln(self::RULE);
        $decoration->writeln('');

        $ci        = $env->isCi($this->stdinIsTty, $this->stdoutIsTty);
        $readOnly  = $env->isReadOnly();
        $aggregate = $env->isAggregate($readOnly);
        $this->announceModes($decoration, $env, $ci, $readOnly, $aggregate);

        $processes = new SymfonyProcessRunner($decoration);
        $phpBin    = $env->string('PHP_QA_CI_PHP_EXECUTABLE') ?? 'php';
        $memory    = $env->string('phpqaMemoryLimit')         ?? '4G';
        $php       = new PhpInvoker($processes, $phpBin, $memory, $paths->varDir);
        if (!is_dir($paths->varDir)) {
            \Safe\mkdir($paths->varDir, 0o777, true);
        }

        $platform = new PlatformDetector()->detect($paths->projectRoot);
        $decoration->writeln($platform->value . ' platform detected');

        $xdebug = $php->hasXdebug();
        $decoration->writeln('');
        $decoration->writeln('Checking for Xdebug');
        $decoration->writeln('-------------------');
        if ($xdebug) {
            $mode = $env->string('XDEBUG_MODE');
            if ('debug' !== $mode) {
                \Safe\putenv('XDEBUG_MODE=coverage');
            }

            $xdebugMode = getenv('XDEBUG_MODE');
            $decoration->writeln('Xdebug is enabled in ' . (false === $xdebugMode || '' === $xdebugMode ? 'default' : $xdebugMode) . ' mode');
        } else {
            $decoration->writeln('Xdebug is not enabled - infection and coverage not available');
        }

        if (null !== $path) {
            $decoration->writeln('Only scanning the specified path ' . $path);
        }

        $builder = QaConfigBuilder::defaults(
            paths: $paths,
            platform: $platform,
            env: $env,
            phpBinPath: $phpBin,
            xdebugEnabled: $xdebug,
            halfCpuThreads: $this->halfCpuThreads($processes, $paths->projectRoot),
            ci: $ci,
            readOnly: $readOnly,
            aggregate: $aggregate,
            jsonOutput: $json,
            singleTool: $request->tool,
            specifiedPath: $path,
        );
        if ('uniterate' === $request->selectedToken) {
            $builder = $builder->withPhpUnitIterativeMode(true);
        }

        try {
            $config = new ProjectConfigLoader($decoration)->apply($builder, $paths->projectConfigDir)->build();

            $context = new ToolContext(
                config: $config,
                configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $platform),
                processes: $processes,
                php: $php,
                logs: new LogArchiver($decoration),
                output: $decoration,
                stdout: $stdout,
            );

            $pipeline = new Pipeline(
                registry: $registry,
                executor: new ToolExecutor(
                    new ShippedToolLocator($tools->tools, $paths->projectConfigDir),
                    $ci ? new NonInteractiveRetryPrompt() : new ConsoleRetryPrompt($decoration, $this->stdinStream),
                    $decoration,
                ),
                lock: RunLock::forProject($paths->projectRoot, $decoration),
                hooks: new HookRunner(),
                directories: new DirectoryPreparer(),
                phars: new PharToolsVerifier(),
                aggregate: new AggregateReport($decoration),
                output: $decoration,
                hostname: \Safe\gethostname(),
                pid: $this->pid(),
            );

            $exit = $pipeline->run($context);
        } catch (Throwable $throwable) {
            $decoration->writeln('');
            $decoration->writeln('ERROR: ' . $throwable->getMessage());
            $decoration->writeln('  in ' . $throwable->getFile() . ':' . $throwable->getLine());

            return 1;
        }

        $decoration->writeln('');
        $decoration->writeln(self::RULE);
        $decoration->writeln(\sprintf('%s qa %s COMPLETED', \Safe\gethostname(), implode(' ', $this->argv)));
        $decoration->writeln(self::RULE);

        return $exit;
    }

    private function announceModes(OutputInterface $out, EnvironmentReader $env, bool $ci, bool $readOnly, bool $aggregate): void
    {
        if ($ci) {
            if ('1' === $env->string('CLAUDECODE')) {
                $out->writeln('Claude Code environment detected - enabling CI mode');
            } elseif (!$env->bool('CI', false)) {
                $out->writeln('Non-interactive environment detected - enabling CI mode');
            }
        }

        if ($readOnly) {
            $out->writeln('QA read-only (check) mode: fixers will NOT modify files; a pending change FAILS the run.');
            $out->writeln('  (override with QA_READONLY=0 to apply fixes)');
        } else {
            $out->writeln('QA writable mode: fixers (Rector, PHP CS Fixer) may modify files.');
            $out->writeln('  (override with QA_READONLY=1 for a read-only check run, as GitHub Actions does)');
        }

        if ($aggregate) {
            $out->writeln('QA aggregate mode: all tools run; failures are collected and reported together (not fail-fast).');
            $out->writeln('  (override with QA_FAIL_FAST=1 to stop at the first failing tool)');
        }
    }

    private function pid(): int
    {
        return \Safe\getmypid();
    }

    /** Half the CPU threads, minimum one: the shared parallelism default for the heavy tools. */
    private function halfCpuThreads(ProcessRunnerInterface $processes, string $cwd): int
    {
        $result = $processes->run(new ProcessSpecDto(['nproc'], $cwd, streamOutput: false));
        $count  = $result->succeeded() ? (int)trim($result->output) : 0;
        if ($count < 1 && is_readable('/proc/cpuinfo')) {
            $count = substr_count(\Safe\file_get_contents('/proc/cpuinfo'), 'processor');
        }

        return max(1, intdiv(max(1, $count), 2));
    }
}
