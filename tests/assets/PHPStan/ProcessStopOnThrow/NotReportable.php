<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/**
 * Nothing here is reported: either the finally stops the same process, or no
 * first-party code runs while the child is alive, or the receiver is not a
 * Process.
 */
final class NotReportable
{
    public function __construct(private readonly Process $process)
    {
    }

    public function callbackRunWhoseFinallyStopsIt(Process $process, Registry $registry): void
    {
        $registry->add($process);

        try {
            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.0);
            }

            $registry->remove($process);
        }
    }

    public function startWhoseFinallyStopsIt(Process $process): void
    {
        try {
            $process->start();
            $this->work();
            $process->wait();
        } finally {
            $process->stop();
        }
    }

    public function guardedByAnOuterTry(): void
    {
        try {
            try {
                $this->process->mustRun(static fn (string $type, string $buffer): bool => '' !== $buffer);
            } catch (\RuntimeException $exception) {
                throw new \LogicException('wrapped', 0, $exception);
            }
        } finally {
            $this->process->stop();
        }
    }

    /** PHP runs the finally after a catch block too, whether the catch completes or throws. */
    public function startInACatchWhoseTryStopsIt(Process $process): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $exception) {
            $process->start();
            $this->work();

            throw $exception;
        } finally {
            $process->stop();
        }
    }

    /** A closure that guards its own call; and a first-class callable, which starts nothing. */
    public function closureGuardingItsOwnCall(Process $process): callable
    {
        $starter = $process->start(...);

        return static function () use ($process, $starter): void {
            try {
                $process->start();
            } finally {
                $process->stop();
            }

            unset($starter);
        };
    }

    public function noCallback(Process $process): int
    {
        $process->mustRun();
        $process->run(null, ['A' => 'b']);
        $process->wait();

        return $process->run();
    }

    public function notAProcess(Runner $runner): void
    {
        $runner->run(static function (string $line): void {
            echo $line;
        });
        $runner->start();
        Launcher::run(static function (): void {
        });
    }

    /** A method named at run time cannot be told apart from any other, so it is not looked at. */
    public function dynamicMethodName(Process $process, string $method): void
    {
        $process->{$method}(static function (string $type, string $buffer): void {
            echo $buffer;
        });
    }

    private function work(): void
    {
    }
}
