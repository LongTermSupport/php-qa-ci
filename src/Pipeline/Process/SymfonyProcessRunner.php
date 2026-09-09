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
 * echoed first so a log shows exactly what ran.
 *
 * @internal
 */
final readonly class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function run(ProcessSpecDto $spec): ProcessResultDto
    {
        $command = $spec->lowPriority ? ['nice', '-n', '19', ...$spec->command] : $spec->command;

        $process = new Process($command, $spec->cwd, [] === $spec->env ? null : [...getenv(), ...$spec->env], null, $spec->timeout);
        $process->setPty(false);

        $this->output->writeln('++ ' . $spec->commandLine());

        $captured = '';
        $stdout   = '';
        $process->run(function (string $type, string $buffer) use ($spec, &$captured, &$stdout): void {
            $captured .= $buffer;
            if (Process::OUT === $type) {
                $stdout .= $buffer;
            }

            if ($spec->streamOutput) {
                $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            }
        });

        return new ProcessResultDto($process->getExitCode() ?? 1, $captured, $stdout);
    }
}
