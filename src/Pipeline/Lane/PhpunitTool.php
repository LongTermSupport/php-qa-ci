<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Lane\Phpunit\PhpunitArguments;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Runs the project's test suite through its own phpunit binary (or paratest
 * when installed). A missing tests/bootstrap.php is seeded with a documented
 * placeholder first. With coverage on, the Xdebug-enabled binary runs under
 * XDEBUG_MODE=coverage so Infection can reuse the result; otherwise Xdebug is
 * stripped. The junit and stdout logs are archived under var/qa/phpunit_logs.
 *
 * Exit 0 passes; 1 and 2 are test failures; anything above is a PHPUnit crash,
 * which is re-run once with --debug for diagnosis and never retried.
 *
 * @internal
 */
final readonly class PhpunitTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpunit';

    private const string LOG_DIR = 'phpunit_logs';

    private const string JUNIT_LOG = 'phpunit.junit.xml';

    private const string STDOUT_LOG = 'phpunit.log';

    private const string BOOTSTRAP_PLACEHOLDER = <<<'PHP_WRAP'
        <?php
    
        declare(strict_types=1);
    
        /**
         * PHPUnit Bootstrap File
         *
         * This is a placeholder bootstrap file created by PHP-QA-CI.
         *
         * PURPOSE:
         * The bootstrap file is executed before PHPUnit runs any tests.
         * It's used to set up the testing environment, including:
         * - Loading the autoloader
         * - Setting environment variables
         * - Initializing framework components
         * - Configuring test databases
         *
         * SYMFONY PROJECTS:
         * For Symfony projects, you should typically:
         * 1. Load the autoloader
         * 2. Use Dotenv to load .env.test
         *
         * Example for Symfony:
         * ```php
         * use Symfony\Component\Dotenv\Dotenv;
         *
         * require dirname(__DIR__).'/vendor/autoload.php';
         *
         * if (method_exists(Dotenv::class, 'bootEnv')) {
         *     (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
         * }
         * ```
         *
         * REPLACE THIS FILE with your project-specific bootstrap logic.
         */
    
        // Load composer autoloader
        require dirname(__DIR__) . '/vendor/autoload.php';
    
        // Uncomment and add your project-specific bootstrap logic here:
        // (static function (): void {
        //     // e.g. set environment variables, initialise framework, configure test database
        // })();
    
        PHP_WRAP;

    public function __construct(private PhpunitArguments $arguments = new PhpunitArguments())
    {
    }

    public function name(): string
    {
        return 'phpunit';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        $paths  = $config->paths;

        $this->seedBootstrap($context);

        $phpunitBinary = $paths->binDir . '/phpunit';
        $coverage      = $config->phpUnit->coverage;

        $versionProbe = $this->invoke($context, $phpunitBinary, ['--version'], [], false);
        $major        = $this->majorVersion($versionProbe->output);
        if (null === $major) {
            $context->writeln('ERROR: could not determine the PHPUnit version from: ' . trim($versionProbe->output));
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed('PHPUnit version probe failed');
        }

        $context->writeln('PHPUnit Major Version: ' . $major);

        $script         = $phpunitBinary;
        $paratestTarget = null;
        $context->writeln('Checking for paratest');
        if (is_file($paths->binDir . '/paratest')) {
            $context->writeln(\sprintf('Found paratest, using this instead of standard %s', $phpunitBinary));
            $script         = $paths->binDir . '/paratest';
            $paratestTarget = $phpunitBinary;
        }

        $logDir   = $context->logDir(self::LOG_DIR);
        $junitLog = $logDir . '/' . self::JUNIT_LOG;
        if (null !== $config->specifiedPath) {
            $context->writeln('Running PHPUnit on specified paths: ' . implode(' ', $config->pathsToCheck));
        }

        $args = $this->arguments->build(
            $config->phpUnit,
            $config->ci,
            $major,
            $context->configPath('phpunit.xml'),
            $junitLog,
            $paratestTarget,
            ...(null === $config->specifiedPath ? [] : $config->pathsToCheck),
        );

        $env = [
            'phpUnitQuickTests' => $config->phpUnit->quickTests ? '1' : '0',
            'XDEBUG_MODE'       => $coverage ? 'coverage' : 'off',
        ];
        $result   = $this->invoke($context, $script, $args, $env, true);
        $exitCode = $result->exitCode;
        \Safe\file_put_contents($logDir . '/' . self::STDOUT_LOG, $result->output);

        if (!is_file($junitLog) || str_contains(\Safe\file_get_contents($junitLog), '<testsuites/>')) {
            $context->writeln('');
            $context->writeln('        ERROR - no tests have been run!');
            $context->writeln('');
            $context->writeln('        Please ensure you have at least one valid test suite configured in your phpunit.xml file');
            $context->writeln('');
            $exitCode = 1;
        }

        $context->writeln('');
        $context->writeln('Log Archival');
        $context->writeln('============');

        $pathSpecific = null !== $config->specifiedPath;
        $context->logs->archive('PHPUnit JUnit XML', $logDir, self::JUNIT_LOG, $pathSpecific, $config->pathsToCheck);
        $context->writeln('');
        $context->logs->archive('PHPUnit stdout', $logDir, self::STDOUT_LOG, $pathSpecific, $config->pathsToCheck);

        $summary = $this->summaryLine($result->output);
        if (null !== $summary) {
            $context->writeln('Result: ' . $summary);
        }

        if (0 === $exitCode) {
            return ToolResultDto::passed();
        }

        if ($exitCode > 2) {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('');
            $context->writeln('PHPUnit Crashed');
            $context->writeln('');
            $context->writeln('Running again with Debug mode...');
            $context->writeln('');
            $context->writeln('');
            $context->php->withoutXdebug($phpunitBinary, [$paths->testsDir, '--debug'], $paths->projectRoot, ['qaQuickTests' => $env['phpUnitQuickTests']]);
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::crashed(\sprintf('PHPUnit crashed (exit %d)', $exitCode));
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('PHPUnit Tests failed (exit %d)', $exitCode));
    }

    private function seedBootstrap(ToolContext $context): void
    {
        $bootstrap = $context->config->paths->testsDir . '/bootstrap.php';
        if (is_file($bootstrap)) {
            return;
        }

        $context->writeln('Creating placeholder bootstrap file at ' . $bootstrap);
        $dir = \dirname($bootstrap);
        if (!is_dir($dir)) {
            \Safe\mkdir($dir, 0o777, true);
        }

        \Safe\file_put_contents($bootstrap, self::BOOTSTRAP_PLACEHOLDER);
        $context->writeln('Placeholder bootstrap file created. Please customize it for your project needs.');
    }

    /**
     * The coverage path must run the Xdebug-enabled binary; every other run
     * strips Xdebug. Both apply the global memory limit.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function invoke(ToolContext $context, string $script, array $args, array $env, bool $streamOutput): ProcessResultDto
    {
        $cwd = $context->config->paths->projectRoot;

        return $context->config->phpUnit->coverage
            ? $context->php->withXdebug($script, $args, $cwd, $env, $streamOutput)
            : $context->php->withoutXdebug($script, $args, $cwd, $env, $streamOutput);
    }

    private function majorVersion(string $versionOutput): ?int
    {
        if (1 !== \Safe\preg_match('/(\d+)\.\d+\.\d+/', $versionOutput, $matches) || !isset($matches[1])) {
            return null;
        }

        return (int)$matches[1];
    }

    /** The last "Tests: N, Assertions: M ..." line, with ANSI colour codes stripped. */
    private function summaryLine(string $output): ?string
    {
        $cleaned = \Safe\preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $output);
        if (!\is_string($cleaned)) {
            return null;
        }

        $summary = null;
        foreach (explode("\n", $cleaned) as $line) {
            if (1 === \Safe\preg_match('/^Tests:.*Assertions:/', $line)) {
                $summary = rtrim($line, "\r");
            }
        }

        return $summary;
    }
}
