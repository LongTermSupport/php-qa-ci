<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;

/**
 * Builds and runs PHP invocations the way every tool needs them: the
 * configured PHP binary, the global memory limit, and (by default) Xdebug
 * switched off so a tool that does not need coverage does not pay for it.
 *
 * Xdebug is switched off through `XDEBUG_MODE=off` in the child's environment,
 * never by loading a different ini: the extension stays loaded but does
 * nothing, the host's ini layout is untouched, and a tool that spawns its own
 * PHP workers (PHPStan, paratest) hands the setting down with the environment.
 *
 * @api
 */
final readonly class PhpInvoker
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const array XDEBUG_OFF = [self::XDEBUG_MODE => 'off'];

    public function __construct(
        private ProcessRunnerInterface $runner,
        private string $phpBinPath,
        private string $memoryLimit,
        private string $varDir,
    ) {
    }

    /**
     * Run a PHP script without Xdebug: `XDEBUG_MODE=off php -d memory_limit=X -f <script> -- <args>`.
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
     * The caller's environment is kept, except that `XDEBUG_MODE` is always `off`:
     * that is what "without Xdebug" means, whatever the caller passed.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function specWithoutXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true, bool $lowPriority = false): ProcessSpecDto
    {
        return new ProcessSpecDto(
            command: [$this->phpBinPath, '-d', 'memory_limit=' . $this->memoryLimit, '-f', $script, '--', ...$args],
            cwd: $cwd,
            env: [...$env, ...self::XDEBUG_OFF],
            streamOutput: $streamOutput,
            lowPriority: $lowPriority,
        );
    }

    /** The PHP version string of the configured binary, e.g. "8.5.10". */
    public function version(): string
    {
        return trim($this->probe('echo PHP_VERSION;'));
    }

    /** Whether the configured binary loads Xdebug (in any mode, including off). */
    public function hasXdebug(): bool
    {
        return '1' === trim($this->probe('echo extension_loaded("xdebug") ? "1" : "0";'));
    }

    /**
     * Run a one-line probe and return what it printed. Xdebug is switched off
     * for the probe so a host Xdebug configured to connect to a debugging
     * client on every request cannot add its "Could not connect" line to the
     * captured answer.
     */
    private function probe(string $code): string
    {
        $result = $this->runner->run(new ProcessSpecDto([$this->phpBinPath, '-r', $code], $this->varDir, self::XDEBUG_OFF, streamOutput: false));

        return $result->output;
    }
}
