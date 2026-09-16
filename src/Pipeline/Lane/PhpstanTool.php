<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Pipeline\Agent\PhpstanJsonParser;
use LTS\PHPQA\Pipeline\Agent\TerseReporter;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Static analysis via the shipped phpstan.phar. The resolved phpstan.neon is
 * wrapped in a generated neon that caps the worker count at half the CPU
 * threads, the same figure Rector and Infection use. Exit 1 means PHPStan ran
 * and found errors; anything above is a crash, which in text mode is re-run
 * with --debug so the fatal that stopped it is visible. In --json mode the
 * report goes to the real stdout untouched and nothing is retried. In agent
 * mode the same JSON is turned into one report file per analysed source file
 * and stdout is held to a count, a path and an instruction.
 *
 * @internal
 */
final readonly class PhpstanTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpstan';

    public const string LOG_DIR = 'phpstan_logs';

    public const string LOG_FILE = 'phpstan.log';

    public const string JSON_FILE = 'phpstan.json';

    public const string REPORT_DIR = 'phpstan-file-reports';

    public const string WRAPPER_NEON = 'phpstan-parallel.neon';

    private const string PHAR = '/phpstan.phar';

    private const string NO_PROGRESS = '--no-progress';

    private const string CRASH_MESSAGE = 'PHPStan crashed (exit %d)';

    private const string ERRORS_FOUND = 'PHPStan found errors';

    private const array PHAR_CONFIG_APIS = [
        'phparkitect.phar'                  => 'Arkitect\\',
        'composer-dependency-analyser.phar' => 'ShipMonk\ComposerDependencyAnalyser\\',
    ];

    private const string TAUTOLOGY_PATTERN = '/alreadyNarrowedType|alwaysTrue|alwaysFalse|impossibleCheck/';

    private const string TAUTOLOGY_NOTE = <<<'TAUTOLOGY'
        NOTE — possible tautology from stronger types
        ---------------------------------------------
        One or more errors above (alreadyNarrowedType / alwaysTrue / alwaysFalse /
        impossibleCheck) report a check PHPStan can already prove from the types alone.

        In TEST code this is usually a GOOD sign: a type-safety improvement has made the
        assertion tautological — the production types now guarantee what the test pinned.
        When that is the case, DELETING the now-redundant assertion (or the whole
        tautological test) is the CORRECT fix, not a workaround. The type system has
        absorbed the guarantee the test used to provide; that is the goal, not a loss of
        coverage. Keep the tests that still exercise real runtime behaviour.

        In PRODUCTION code the same identifiers usually flag genuinely dead or redundant
        logic — simplify it (remove the impossible branch / redundant comparison).

        Either way, fix the CAUSE. Do NOT silence it with treatPhpDocTypesAsCertain:false,
        a baseline entry, or an inline PHPStan ignore comment.
        TAUTOLOGY;

    public function name(): string
    {
        return 'phpstan';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config  = $context->config;
        $logDir  = $context->logDir(self::LOG_DIR);
        $wrapper = $this->writeWrapperNeon($context, $logDir);
        $context->writeln(\sprintf('PHPStan: limiting to %d parallel processes (50%% of cores)', $config->halfCpuThreads));

        /*
         * type-coverage refuses to report when the analysed paths differ from
         * the ones the config declares, because a percentage measured over a
         * subset is misleading. Passing the paths on the command line always
         * trips that check, so when the floors are on and the whole project is
         * being analysed the paths go into the wrapper neon instead and no path
         * argument is given. A `-p` run keeps the command-line form, which
         * leaves type-coverage correctly silent for that run.
         */
        $pathArgs = $this->pathsBelongInConfig($config) ? [] : $config->pathsToCheck;
        $baseArgs = ['analyse', ...$pathArgs, '-c', $wrapper];

        if ($config->agentMode) {
            return $this->runAgent($context, $logDir, ...$baseArgs);
        }

        if ($config->jsonOutput) {
            return $this->runJson($context, $logDir, ...$baseArgs);
        }

        return $this->runText($context, $logDir, ...$baseArgs);
    }

    /**
     * Agent mode: the same analysis, reported for a machine. The findings go
     * into one JSON file per source file and stdout gets a count, a path and
     * an instruction.
     *
     * The scope is cleared before anything is written, which is the only
     * reliable way to make an absent report mean "clean": PHPStan's JSON names
     * the files that HAVE errors and says nothing about the rest, so without
     * the clear a fixed file would keep yesterday's red report for ever.
     */
    private function runAgent(ToolContext $context, string $logDir, string ...$baseArgs): ToolResultDto
    {
        $config  = $context->config;
        $writer  = new FileReportWriter($context->logDir(self::REPORT_DIR), $config->paths->projectRoot);
        $terse   = new TerseReporter($context->stdout);
        $scope   = null === $config->specifiedPath ? null : $config->paths->projectRoot . '/' . $config->specifiedPath;
        $logPath = $logDir . '/' . self::JSON_FILE;
        $runAt   = time();

        $result = $context->php->withoutXdebug(
            $config->paths->pharDir . self::PHAR,
            array_values([...$baseArgs, self::NO_PROGRESS, '--error-format=json']),
            $config->paths->projectRoot,
            streamOutput: false,
        );
        \Safe\file_put_contents($logPath, $result->stdout);

        $writer->clearScope($scope);

        if ($result->exitCode > 1) {
            return $this->agentCrash($writer, $terse, \sprintf(self::CRASH_MESSAGE, $result->exitCode), $scope, $runAt, $logPath);
        }

        try {
            $parsed = new PhpstanJsonParser()->parse($result->stdout);
        } catch (UnreadableReportException $unreadableReportException) {
            return $this->agentCrash($writer, $terse, $unreadableReportException->getMessage(), $scope, $runAt, $logPath);
        }

        foreach ($parsed->files as $file => $errors) {
            $writer->write(FileReportDto::forErrors($this->name(), $file, $runAt, $logPath, ...$errors));
        }

        // A -p run naming a single file always gets a report, clean or not:
        // that write is what visibly erases the previous red one.
        if (null !== $scope && is_file($scope) && !\array_key_exists($scope, $parsed->files)) {
            $writer->write(FileReportDto::forErrors($this->name(), $scope, $runAt, $logPath));
        }

        $status = AgentStatusEnum::forErrorCount($parsed->errorCount());
        $index  = $writer->writeIndex($this->name(), $status, $runAt, ...$parsed->globalErrors);
        $single = null !== $scope && is_file($scope);
        $terse->result(
            $this->name(),
            $status,
            $parsed->errorCount(),
            $single ? 1 : $parsed->fileCount(),
            $single ? $writer->reportPathFor($scope) : $index,
        );

        return AgentStatusEnum::Clean === $status ? ToolResultDto::passed() : ToolResultDto::failed(self::ERRORS_FOUND);
    }

    /**
     * The analyser did not produce a verdict. Recorded against the analysed
     * file when there is one, so the agent's next read of that report finds
     * the reason rather than a stale green.
     */
    private function agentCrash(FileReportWriter $writer, TerseReporter $terse, string $reason, ?string $scope, int $runAt, string $logPath): ToolResultDto
    {
        $path   = $scope ?? $logPath;
        $report = $writer->write(FileReportDto::crashed($this->name(), $path, $runAt, $reason, $logPath));
        $writer->writeIndex($this->name(), AgentStatusEnum::Crashed, $runAt, $reason);
        $terse->crashed($this->name(), $reason, $report);

        return ToolResultDto::crashed($reason);
    }

    private function runJson(ToolContext $context, string $logDir, string ...$baseArgs): ToolResultDto
    {
        $config = $context->config;
        $result = $context->php->withoutXdebug(
            $config->paths->pharDir . self::PHAR,
            array_values([...$baseArgs, self::NO_PROGRESS, '--error-format=json']),
            $config->paths->projectRoot,
            streamOutput: false,
        );

        \Safe\file_put_contents($logDir . '/' . self::JSON_FILE, $result->stdout);
        $context->stdout->write($result->stdout, false, OutputInterface::OUTPUT_RAW);
        $context->logs->archive('PHPStan', $logDir, self::JSON_FILE, null !== $config->specifiedPath, $config->pathsToCheck);

        if ($result->exitCode > 1) {
            $context->writeln(\sprintf('PHPStan crashed (exit code: %d)', $result->exitCode));

            return ToolResultDto::crashed(\sprintf(self::CRASH_MESSAGE, $result->exitCode));
        }

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(self::ERRORS_FOUND);
    }

    private function runText(ToolContext $context, string $logDir, string ...$baseArgs): ToolResultDto
    {
        $config = $context->config;
        $phar   = $config->paths->pharDir . self::PHAR;
        $args   = array_values($config->ci ? [...$baseArgs, self::NO_PROGRESS] : $baseArgs);

        $result = $context->php->withoutXdebug($phar, $args, $config->paths->projectRoot);

        \Safe\file_put_contents($logDir . '/' . self::LOG_FILE, $result->output);
        $context->logs->archive('PHPStan', $logDir, self::LOG_FILE, null !== $config->specifiedPath, $config->pathsToCheck);

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if ($result->exitCode > 1) {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('PHPStan Crashed!!....');
            $context->writeln('');
            $context->writeln('running again with debug mode:');
            $context->writeln('Where ever it stops is probably a fatal PHP error');
            $context->writeln('');
            $context->php->withoutXdebug($phar, array_values([...$baseArgs, '--debug', '-v']), $config->paths->projectRoot);

            return ToolResultDto::crashed(\sprintf(self::CRASH_MESSAGE, $result->exitCode));
        }

        if (1 === \Safe\preg_match(self::TAUTOLOGY_PATTERN, $result->output)) {
            $context->writeln('');
            $context->writeln(self::TAUTOLOGY_NOTE);
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(self::ERRORS_FOUND);
    }

    /**
     * Generate the neon that includes the resolved config, caps the worker
     * count and applies the project's type-coverage floors. The floors go here
     * rather than in the shipped phpstan.neon so a project that has copied that
     * file still gets them from qa.php.
     */
    private function writeWrapperNeon(ToolContext $context, string $logDir): string
    {
        $wrapper = $logDir . '/' . self::WRAPPER_NEON;
        $neon    = \sprintf(
            "includes:\n    - %s\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %d\n",
            $context->configPath('phpstan.neon'),
            $context->config->halfCpuThreads,
        );

        $scanDirectories = $this->pharConfigApiDirectories($context->config->paths->pharDir);
        if ([] !== $scanDirectories) {
            $neon .= "    scanDirectories:\n";
            foreach ($scanDirectories as $directory) {
                $neon .= \sprintf("        - %s\n", $directory);
            }
        }

        $config = $context->config;
        if ($config->typeCoverage->enabled()) {
            $neon .= "    type_coverage:\n";
            foreach ($config->typeCoverage->neonParameters() as $key => $floor) {
                $neon .= \sprintf("        %s: %d\n", $key, $floor);
            }
        }

        if ($this->pathsBelongInConfig($config)) {
            $neon .= "    paths:\n";
            foreach ($config->pathsToCheck as $path) {
                $neon .= \sprintf("        - %s\n", $path);
            }
        }

        \Safe\file_put_contents($wrapper, $neon);

        return $wrapper;
    }

    /**
     * The source directories of the phar tools' public config APIs, so PHPStan
     * can discover those symbols.
     *
     * A project's `qaConfig/phparkitect.php` and `qaConfig/composer-dependency-analyser.php`
     * are ordinary PHP files naming classes that exist only inside the matching
     * phar. Analysed with no way to reach those classes they produce a hundred
     * `class.notFound` errors, so the per-file run on them is red for ever and
     * red at nothing the consumer can fix. Scanning is discovery only: PHPStan
     * parses the sources for symbols and executes nothing.
     *
     * Each phar's own Composer PSR-4 map is the source of truth, already
     * resolved to `phar://` paths, so a rebuilt phar that moves its sources
     * keeps working rather than silently going back to red. PHAR_CONFIG_APIS
     * names the phars a project writes a `qaConfig/` file against, each mapped
     * to the PSR-4 prefix its config API lives under.
     *
     * @return list<string>
     */
    private function pharConfigApiDirectories(string $pharDir): array
    {
        $directories = [];
        foreach (self::PHAR_CONFIG_APIS as $phar => $prefix) {
            $pharPath = $pharDir . '/' . $phar;
            $map      = 'phar://' . $pharPath . '/vendor/composer/autoload_psr4.php';
            if (!is_file($pharPath) || !is_file($map)) {
                continue;
            }

            $loaded = require $map;
            if (!\is_array($loaded) || !isset($loaded[$prefix]) || !\is_array($loaded[$prefix])) {
                continue;
            }

            foreach ($loaded[$prefix] as $directory) {
                if (\is_string($directory) && is_dir($directory)) {
                    $directories[] = $directory;
                }
            }
        }

        return $directories;
    }

    /**
     * type-coverage compares the analysed paths against the configured ones and
     * stays silent when they differ. They only agree when the paths come from
     * the config, which is worth doing solely for a whole-project run with the
     * floors switched on.
     */
    private function pathsBelongInConfig(QaConfigDto $config): bool
    {
        return $config->typeCoverage->enabled() && null === $config->specifiedPath;
    }
}
