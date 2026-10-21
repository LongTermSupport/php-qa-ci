<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * Runs a command with symfony/process, streaming its output to the console
 * as it arrives and capturing it for the caller (the equivalent of
 * `cmd 2>&1 | tee`), with stdout also captured on its own so structured
 * output (PHPStan --json) is never polluted by stderr. The command line is
 * echoed first so a log shows exactly what ran. The child is registered in
 * $running while it runs, so an interrupted run can stop it. A child a signal
 * killed is recorded in $signalled and comes back as exit 128 + the signal
 * (see signalled()).
 *
 * @internal
 */
final readonly class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private OutputInterface $output,
        private RunningProcesses $running = new RunningProcesses(),
        private ?SignalledChildren $signalled = null,
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
        $signaled = null;
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
        } catch (ProcessSignaledException $processSignaledException) {
            $signaled = $processSignaledException;
        } finally {
            $this->running->remove($process);
            // A no-op once the child has exited; a run ended by a throw would otherwise leave it running, unregistered.
            $process->stop(0);
        }

        if ($signaled instanceof ProcessSignaledException) {
            return $this->signalled($spec, $signaled, $captured, $stdout);
        }

        return new ProcessResultDto($process->getExitCode() ?? 1, $captured, $stdout);
    }

    /**
     * A child killed by a signal the run did not send: a crash its output says
     * nothing about. It is named on the console, and recorded so the lane that
     * ran it is reported as crashed; with no record to put it in, it is thrown,
     * named, rather than handed back as an exit code a caller may take for an
     * answer.
     */
    private function signalled(ProcessSpecDto $spec, ProcessSignaledException $signaled, string $captured, string $stdout): ProcessResultDto
    {
        $child = new SignalledChildDto($spec->commandLine(), $signaled->getSignal());
        if (!$this->signalled instanceof SignalledChildren) {
            throw new RuntimeException($child->describe(), 0, $signaled);
        }

        $this->signalled->record($child);
        $this->output->writeln('!! ' . $child->describe());

        return new ProcessResultDto($child->exitCode(), $captured, $stdout, $child->signal);
    }
}
