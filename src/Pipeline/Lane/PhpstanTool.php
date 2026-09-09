<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
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
 * report goes to the real stdout untouched and nothing is retried.
 *
 * @internal
 */
final readonly class PhpstanTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpstan';

    public const string LOG_DIR = 'phpstan_logs';

    public const string LOG_FILE = 'phpstan.log';

    public const string JSON_FILE = 'phpstan.json';

    public const string WRAPPER_NEON = 'phpstan-parallel.neon';

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

        $baseArgs = ['analyse', ...$config->pathsToCheck, '-c', $wrapper];

        if ($config->jsonOutput) {
            return $this->runJson($context, $logDir, $baseArgs);
        }

        return $this->runText($context, $logDir, $baseArgs);
    }

    /** @param list<string> $baseArgs */
    private function runJson(ToolContext $context, string $logDir, array $baseArgs): ToolResultDto
    {
        $config = $context->config;
        $result = $context->php->withoutXdebug(
            $config->paths->pharDir . '/phpstan.phar',
            [...$baseArgs, '--no-progress', '--error-format=json'],
            $config->paths->projectRoot,
            streamOutput: false,
        );

        \Safe\file_put_contents($logDir . '/' . self::JSON_FILE, $result->stdout);
        $context->stdout->write($result->stdout, false, OutputInterface::OUTPUT_RAW);
        $context->logs->archive('PHPStan', $logDir, self::JSON_FILE, null !== $config->specifiedPath, $config->pathsToCheck);

        if ($result->exitCode > 1) {
            $context->writeln(\sprintf('PHPStan crashed (exit code: %d)', $result->exitCode));

            return ToolResultDto::crashed(\sprintf('PHPStan crashed (exit %d)', $result->exitCode));
        }

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('PHPStan found errors');
    }

    /** @param list<string> $baseArgs */
    private function runText(ToolContext $context, string $logDir, array $baseArgs): ToolResultDto
    {
        $config = $context->config;
        $phar   = $config->paths->pharDir . '/phpstan.phar';
        $args   = $config->ci ? [...$baseArgs, '--no-progress'] : $baseArgs;

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
            $context->php->withoutXdebug($phar, [...$baseArgs, '--debug', '-v'], $config->paths->projectRoot);

            return ToolResultDto::crashed(\sprintf('PHPStan crashed (exit %d)', $result->exitCode));
        }

        if (1 === \Safe\preg_match(self::TAUTOLOGY_PATTERN, $result->output)) {
            $context->writeln('');
            $context->writeln(self::TAUTOLOGY_NOTE);
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('PHPStan found errors');
    }

    /** Generate the neon that includes the resolved config and caps the worker count. */
    private function writeWrapperNeon(ToolContext $context, string $logDir): string
    {
        $wrapper = $logDir . '/' . self::WRAPPER_NEON;
        $neon    = \sprintf(
            "includes:\n    - %s\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %d\n",
            $context->configPath('phpstan.neon'),
            $context->config->halfCpuThreads,
        );
        \Safe\file_put_contents($wrapper, $neon);

        return $wrapper;
    }
}
