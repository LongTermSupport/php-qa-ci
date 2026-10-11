<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/**
 * A process started in a loop inside the try, held in a variable the loop
 * sets, is a different process on each pass: a finally that stops that
 * variable once stops only the last. Its stop must loop too. The first group
 * is reported; the second is clean.
 */
final class LoopStart
{
    public ?Process $process = null;

    /** @param list<Process> $processes */
    public function finallyStopsOnlyTheLast(array $processes): void
    {
        try {
            foreach ($processes as $process) {
                $process->start();
            }

            $this->work();
        } finally {
            $process->stop(0);
        }
    }

    public function assignedInAForLoop(int $count): void
    {
        try {
            for ($i = 0; $i < $count; ++$i) {
                $process = new Process(['sleep', '1']);
                $process->start();
            }
        } finally {
            $process->stop(0);
        }
    }

    /** @param list<self> $holders */
    public function aPropertyOfTheLoopVariable(array $holders): void
    {
        try {
            foreach ($holders as $holder) {
                $holder->process?->start();
            }
        } finally {
            $holder->process?->stop(0);
        }
    }

    /** @param list<Process> $processes */
    public function finallyLoopsButOverAnotherVariable(array $processes): void
    {
        try {
            foreach ($processes as $process) {
                $process->start();
            }
        } finally {
            foreach ($processes as $other) {
                $process->stop(0);
                $other->clearOutput();
            }
        }
    }

    /** @param list<Process> $queue */
    public function assignedInAWhileCondition(array $queue): void
    {
        try {
            while (null !== ($process = array_shift($queue))) {
                $process->start();
            }
        } finally {
            $process->stop(0);
        }
    }

    /** @param list<array{string, Process}> $named */
    public function destructuredInTheForeach(array $named): void
    {
        try {
            foreach ($named as [$name, $process]) {
                $process->start();
                echo $name;
            }
        } finally {
            $process->stop(0);
        }
    }

    /** @param list<Process> $processes */
    public function assignedInADoLoop(array $processes): void
    {
        try {
            do {
                [$process] = $processes;
                $process->start();
            } while ([] !== $processes);
        } finally {
            $process->stop(0);
        }
    }

    /** @param list<Process> $processes */
    public function finallyStopsEveryOne(array $processes): void
    {
        try {
            foreach ($processes as $name => $process) {
                $process->start();
                echo $name;
            }

            $this->work();
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    /** The same process restarted on each pass: the one stop covers it. */
    public function aPropertyRestartedInALoop(iterable $items): void
    {
        try {
            foreach ($items as $item) {
                $this->process?->start();
                $this->process?->wait();
            }
        } finally {
            $this->process?->stop(0);
        }
    }

    /** @param list<Process> $processes */
    public function eachPassGuardsItsOwn(array $processes): void
    {
        foreach ($processes as $process) {
            try {
                $process->start();
            } finally {
                $process->stop(0);
            }
        }
    }

    public function notTheLoopVariable(Process $process, iterable $items): void
    {
        try {
            foreach ($items as $item) {
                $process->start();
                $process->wait();
            }
        } finally {
            $process->stop(0);
        }
    }

    private function work(): void
    {
    }
}
