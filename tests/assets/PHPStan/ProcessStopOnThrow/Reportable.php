<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Every call here lets first-party code run while the child is alive with no
 * finally that stops it, so each is reported.
 */
final class Reportable
{
    public bool $stop = false;

    public ?\Closure $stopLater = null;

    public function __construct(private readonly Process $process)
    {
    }

    public function callbackRunWithNoTry(Process $process): void
    {
        $process->run(static function (string $type, string $buffer): void {
            echo $buffer;
        });
    }

    /** The originating shape: the finally does something, but not stop. */
    public function callbackRunWhoseFinallyOnlyUnregisters(Process $process, Registry $registry): void
    {
        $registry->add($process);

        try {
            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });
        } finally {
            $registry->remove($process);
        }
    }

    public function finallyStopsADifferentProcess(Process $process, Process $other): void
    {
        try {
            $process->mustRun(static fn (string $type, string $buffer): bool => '' !== $buffer);
        } finally {
            $other->stop();
        }
    }

    public function startWithNoTry(Process $process): void
    {
        $process->start();
        $this->work();
        $process->wait();
    }

    public function callInTheFinallyRatherThanTheTry(Process $process): void
    {
        try {
            $this->work();
        } finally {
            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });
            $process->stop();
        }
    }

    /** The finally stops a different process, so the catch is not guarded either. */
    public function callInACatchWhoseTryHasNoStoppingFinally(Process $process, Process $other): void
    {
        try {
            $other->start();
        } catch (RuntimeException $exception) {
            $process->start();
            $this->work();

            throw $exception;
        } finally {
            $other->stop();
        }
    }

    public function callInAClosureDefinedInTheTry(Process $process): callable
    {
        try {
            $this->work();

            return static function () use ($process): void {
                $process->start();
            };
        } finally {
            $process->stop();
        }
    }

    public function waitWithACallbackOnAProperty(): void
    {
        $this->process->wait(static function (string $type, string $buffer): void {
            echo $buffer;
        });
    }

    public function waitUntilOnASubclass(ChildProcess $process): void
    {
        $process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'ready'));
    }

    public function namedCallbackArgument(Process $process): void
    {
        $process->run(callback: static function (string $type, string $buffer): void {
            echo $buffer;
        });
    }

    public function nullsafeStart(?Process $process): void
    {
        $process?->start();
    }

    /** The stop is in a closure the finally only defines, so it never runs there. */
    public function finallyDefinesAStopItNeverCalls(Process $process): void
    {
        try {
            $process->start();
        } finally {
            $this->stopLater = static fn (): ?int => $process->stop();
        }
    }

    /** The finally calls the process, but not stop(); and assigns a property named stop. */
    public function finallyCallsSomethingElseOnTheProcess(Process $process): void
    {
        try {
            $process->start();
        } finally {
            $process->clearOutput();
            $this->stop = true;
        }
    }

    /** The closure is defined in the second catch, but runs later, after the finally. */
    public function closureDefinedInACatch(Process $process): void
    {
        try {
            $this->work();
        } catch (\LogicException) {
            $this->work();
        } catch (RuntimeException) {
            $this->stopLater = static function () use ($process): void {
                $process->start();
            };
        } finally {
            $process->stop();
        }
    }

    private function work(): void
    {
    }
}
