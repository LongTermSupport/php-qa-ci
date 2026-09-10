<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Config\Dto\OpcacheSettingsDto;
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

    private const string PROBE_DELIMITER = '|';

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
     * @param array<string, string> $ini  extra `-d` overrides, after the memory limit
     */
    public function withoutXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true, bool $lowPriority = false, array $ini = []): ProcessResultDto
    {
        return $this->runner->run($this->specWithoutXdebug($script, $args, $cwd, $env, $streamOutput, $lowPriority, $ini));
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
     * @param array<string, string> $ini  extra `-d` overrides, after the memory limit
     */
    public function specWithoutXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true, bool $lowPriority = false, array $ini = []): ProcessSpecDto
    {
        $overrides = [];
        foreach ($ini as $directive => $value) {
            $overrides[] = '-d';
            $overrides[] = $directive . '=' . $value;
        }

        return new ProcessSpecDto(
            command: [$this->phpBinPath, '-d', 'memory_limit=' . $this->memoryLimit, ...$overrides, '-f', $script, '--', ...$args],
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
     * What the configured binary reports about OPcache, in one probe. An
     * unreadable answer reads as "not loaded": the callers only ever use this
     * to decide whether to warn or to compile, and neither is worth guessing at.
     */
    public function opcacheSettings(): OpcacheSettingsDto
    {
        // Two fields separated by a delimiter, not JSON: a binary that cannot
        // answer (a crash, a fatal from a broken ini) then reads as "not
        // loaded" without a parse that can itself fail.
        $answer = explode(self::PROBE_DELIMITER, trim($this->probe(\sprintf(
            'echo extension_loaded("Zend OPcache") ? "1" : "0", "%s", (string) ini_get("opcache.optimization_level");',
            self::PROBE_DELIMITER,
        ))), 2);

        // Both fields, or neither: a partial answer is not an answer.
        if (2 !== \count($answer)) {
            return new OpcacheSettingsDto(false, '');
        }

        return new OpcacheSettingsDto('1' === $answer[0], $answer[1]);
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
