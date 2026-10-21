<?php

declare(strict_types=1);

namespace PhpQaCiFixture\UnhandledProcessSignal;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class Calls
{
    private ?Process $process = null;

    public function unguarded(Process $process): void
    {
        $process->run();
        $process->mustRun();
        $process->wait();
        $this->process?->run();
    }

    public function guarded(Process $process): int
    {
        try {
            $process->run();
            $process->mustRun();
        } catch (ProcessSignaledException $signaled) {
            return $signaled->getSignal();
        }

        try {
            return $process->wait();
        } catch (ProcessFailedException|\Symfony\Component\Process\Exception\ProcessSignaledException $exception) {
            return $exception->getCode();
        }
    }

    public function inTheCatchOrFinallyOfAGuardingTry(Process $process): void
    {
        try {
            $process->start();
        } catch (ProcessSignaledException $signaled) {
            $process->run();
        } finally {
            $process->wait();
        }
    }

    public function inAClosureDeclaredInAGuardingTry(Process $process): callable
    {
        try {
            $process->wait();

            return static fn (): int => $process->run();
        } catch (ProcessSignaledException $signaled) {
            throw new RuntimeException('signal ' . $signaled->getSignal(), 0, $signaled);
        }
    }

    public function caughtOnlyAsASupertype(Process $process): void
    {
        try {
            $process->run();
        } catch (RuntimeException $exception) {
            throw new RuntimeException('failed', 0, $exception);
        }
    }

    public function notAWaitOrNotAProcess(Process $process, Runner $runner, string $method): callable
    {
        $process->{$method}();
        $process->start();
        $process->waitUntil(static fn (): bool => true);
        $runner->run();

        return $process->run(...);
    }
}

final class Runner
{
    public function run(): void
    {
    }
}
