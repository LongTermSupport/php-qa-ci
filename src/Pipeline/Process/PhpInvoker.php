<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;

/**
 * Builds and runs PHP invocations the way every tool needs them: the
 * configured PHP binary, the global memory limit, and (by default) an ini
 * set with Xdebug stripped out so a tool that does not need coverage does not
 * pay for it. The no-Xdebug ini is generated once per PHP version under var/qa
 * by asking the target binary which ini files it loads.
 *
 * @api
 */
final readonly class PhpInvoker
{
    public function __construct(
        private ProcessRunnerInterface $runner,
        private string $phpBinPath,
        private string $memoryLimit,
        private string $varDir,
    ) {
    }

    /**
     * Run a PHP script without Xdebug: `php -n -c <no-xdebug.ini> -d memory_limit=X -f <script> -- <args>`.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function withoutXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true, bool $lowPriority = false): ProcessResultDto
    {
        return $this->runner->run($this->specWithoutXdebug($script, $args, $cwd, $env, $streamOutput, $lowPriority));
    }

    /**
     * Run a PHP script with the full ini set (Xdebug loaded when installed),
     * for coverage runs. XDEBUG_MODE is the caller's to set via $env.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function withXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true): ProcessResultDto
    {
        return $this->runner->run(new ProcessSpecDto(
            command: [$this->phpBinPath, '-d', 'memory_limit=' . $this->memoryLimit, '-f', $script, '--', ...$args],
            cwd: $cwd,
            env: $env,
            streamOutput: $streamOutput,
        ));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function specWithoutXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true, bool $lowPriority = false): ProcessSpecDto
    {
        return new ProcessSpecDto(
            command: [$this->phpBinPath, '-n', '-c', $this->noXdebugIni(), '-d', 'memory_limit=' . $this->memoryLimit, '-f', $script, '--', ...$args],
            cwd: $cwd,
            env: $env,
            streamOutput: $streamOutput,
            lowPriority: $lowPriority,
        );
    }

    /** The PHP version string of the configured binary, e.g. "8.5.10". */
    public function version(): string
    {
        $result = $this->runner->run(new ProcessSpecDto([$this->phpBinPath, '-r', 'echo PHP_VERSION;'], $this->varDir, streamOutput: false));

        return trim($result->output);
    }

    /** Whether the configured binary loads Xdebug. */
    public function hasXdebug(): bool
    {
        $result = $this->runner->run(new ProcessSpecDto([$this->phpBinPath, '-r', 'echo extension_loaded("xdebug") ? "1" : "0";'], $this->varDir, streamOutput: false));

        return '1' === trim($result->output);
    }

    /**
     * The path of the no-Xdebug ini for the configured binary, generated on
     * first use: every ini file the binary loads, concatenated, minus any
     * whose name mentions xdebug.
     */
    public function noXdebugIni(): string
    {
        $path = $this->varDir . '/phpqa-no-xdebug.' . $this->version() . '.ini';
        if (is_file($path)) {
            return $path;
        }

        $result = $this->runner->run(new ProcessSpecDto(
            [$this->phpBinPath, '-r', 'echo php_ini_loaded_file(), PHP_EOL, php_ini_scanned_files();'],
            $this->varDir,
            streamOutput: false,
        ));

        $ini = '';
        foreach (\Safe\preg_split('/[\n,]/', $result->output) as $file) {
            if (!\is_string($file)) {
                continue;
            }

            $file = trim($file);
            if ('' === $file || str_contains($file, 'xdebug') || !is_file($file)) {
                continue;
            }

            $ini .= \Safe\file_get_contents($file) . "\n";
        }

        if (!is_dir($this->varDir)) {
            \Safe\mkdir($this->varDir, 0o777, true);
        }

        \Safe\file_put_contents($path, $ini);

        return $path;
    }
}
