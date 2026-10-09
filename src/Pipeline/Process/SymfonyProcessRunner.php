<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Runs a command with symfony/process, streaming its output to the console
 * as it arrives and capturing it for the caller (the equivalent of
 * `cmd 2>&1 | tee`), with stdout also captured on its own so structured
 * output (PHPStan --json) is never polluted by stderr. The command line is
 * echoed first so a log shows exactly what ran. The child is registered in
 * $running while it runs, so an interrupted run can stop it.
 *
 * @internal
 */
final readonly class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private OutputInterface $output,
        private RunningProcesses $running = new RunningProcesses(),
    ) {
    }

    /**
     * The environment a child starts with: the parent's, with Xdebug off
     * unless the spec says otherwise. With OPcache JIT on, a loaded Xdebug
     * prints a "JIT is incompatible" warning on stdout at every PHP start, which
     * corrupts any output a caller parses; `XDEBUG_MODE=off` prevents it. Only a
     * coverage lane (PHPUnit, Infection) names a mode in its spec, so only it
     * keeps Xdebug live.
     *
     * @param array<string, string> $parent
     * @param array<string, string> $specEnv
     *
     * @return array<string, string>
     */
    public static function childEnvironment(array $parent, array $specEnv): array
    {
        return [...$parent, 'XDEBUG_MODE' => 'off', ...$specEnv];
    }

    public function run(ProcessSpecDto $spec): ProcessResultDto
    {
        $command = $spec->lowPriority ? ['nice', '-n', '19', ...$spec->command] : $spec->command;

        $process = new Process($command, $spec->cwd, self::childEnvironment(getenv(), $spec->env), null, $spec->timeout);
        $process->setPty(false);

        $this->output->writeln('++ ' . $spec->commandLine());

        $captured = '';
        $stdout   = '';
        $this->running->add($process);

        try {
            $process->run(function (string $type, string $buffer) use ($spec, &$captured, &$stdout): void {
                $captured .= $buffer;
                if (Process::OUT === $type) {
                    $stdout .= $buffer;
                }

                if ($spec->streamOutput) {
                    $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
                }
            });
        } finally {
            $this->running->remove($process);
        }

        return new ProcessResultDto($process->getExitCode() ?? 1, $captured, $stdout);
    }
}
