<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Config\CoverageDriverEnum;
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
 * Every invocation sends PHP's displayed errors to stderr: the CLI displays
 * them on stdout unless an ini says otherwise, where a warning or deprecation
 * would become part of what a caller parses, a JSON report or a probe's answer.
 *
 * @api
 */
final readonly class PhpInvoker
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const array XDEBUG_OFF = [self::XDEBUG_MODE => 'off'];

    private const string PROBE_DELIMITER = '|';

    private const string DISPLAY_ERRORS_ON_STDERR = 'display_errors=stderr';

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
     * Run a PHP script with the full ini set (Xdebug loaded when installed).
     * XDEBUG_MODE is the caller's to set via $env. The pipeline's own coverage
     * runs use withCoverage(), which also serves pcov.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function withXdebug(string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true): ProcessResultDto
    {
        return $this->runner->run(new ProcessSpecDto(
            command: $this->scriptCommand($script, [], ...$args),
            cwd: $cwd,
            env: $env,
            streamOutput: $streamOutput,
        ));
    }

    /**
     * Run a PHP script that collects coverage with $driver: the driver's ini
     * (pcov enabled over the project, see CoverageDriverEnum::phpIni()) after
     * the memory limit, and the driver's `XDEBUG_MODE` over the caller's. $cwd
     * is the project root, the tree pcov instruments.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function withCoverage(CoverageDriverEnum $driver, string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true): ProcessResultDto
    {
        return $this->runner->run($this->specWithCoverage($driver, $script, $args, $cwd, $env, $streamOutput));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function specWithCoverage(CoverageDriverEnum $driver, string $script, array $args, string $cwd, array $env = [], bool $streamOutput = true): ProcessSpecDto
    {
        return new ProcessSpecDto(
            command: $this->scriptCommand($script, $driver->phpIni($cwd), ...$args),
            cwd: $cwd,
            env: [...$env, ...$driver->environment()],
            streamOutput: $streamOutput,
        );
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
        return new ProcessSpecDto(
            command: $this->scriptCommand($script, $ini, ...$args),
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

    /**
     * The coverage driver the configured binary offers, in one probe: pcov when
     * it is loaded and enabled (the test PHPUnit makes), else Xdebug when it is
     * loaded, else none. An unreadable answer reads as none.
     */
    public function coverageDriver(): CoverageDriverEnum
    {
        $answer = explode(self::PROBE_DELIMITER, trim($this->probe(\sprintf(
            'echo extension_loaded("pcov") && "1" === ini_get("pcov.enabled") ? "1" : "0", "%s", extension_loaded("xdebug") ? "1" : "0";',
            self::PROBE_DELIMITER,
        ))), 2);

        // Both fields, or neither: a partial answer is not an answer.
        if (2 !== \count($answer)) {
            return CoverageDriverEnum::None;
        }

        return CoverageDriverEnum::select('1' === $answer[0], '1' === $answer[1]);
    }

    /** Whether the configured binary loads Xdebug (in any mode, including off). */
    public function hasXdebug(): bool
    {
        return '1' === trim($this->probe('echo extension_loaded("xdebug") ? "1" : "0";'));
    }

    /**
     * The JIT ini the configured binary holds, as `enable_cli|jit|jit_buffer_size`,
     * for XdebugJitAdvisory to judge. A binary that cannot answer yields text
     * that advisory reads as "JIT not on".
     */
    public function jitSettings(): string
    {
        return trim($this->probe('echo ini_get("opcache.enable_cli"), "|", ini_get("opcache.jit"), "|", ini_get("opcache.jit_buffer_size");'));
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
     * `php -d memory_limit=X -d display_errors=stderr [-d <ini>...] -f <script> -- <args>`.
     *
     * @param array<string, string> $ini
     *
     * @return list<string>
     */
    private function scriptCommand(string $script, array $ini, string ...$args): array
    {
        $command = [$this->phpBinPath, '-d', 'memory_limit=' . $this->memoryLimit, '-d', self::DISPLAY_ERRORS_ON_STDERR];
        foreach ($ini as $directive => $value) {
            $command[] = '-d';
            $command[] = $directive . '=' . $value;
        }

        return [...$command, '-f', $script, '--', ...array_values($args)];
    }

    /**
     * Run a one-line probe and return what it printed. Xdebug is switched off
     * for the probe so a host Xdebug configured to connect to a debugging
     * client on every request cannot add its "Could not connect" line to the
     * captured answer, and only stdout is read, with displayed errors sent to
     * stderr, so a warning cannot either.
     */
    private function probe(string $code): string
    {
        $result = $this->runner->run(new ProcessSpecDto([$this->phpBinPath, '-d', self::DISPLAY_ERRORS_ON_STDERR, '-r', $code], $this->varDir, self::XDEBUG_OFF, streamOutput: false));

        return $result->stdout;
    }
}
