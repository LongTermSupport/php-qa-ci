<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use Safe\Exceptions\PosixException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;

/**
 * The child processes running right now, so an interrupted run can stop its
 * tools rather than leave them orphaned. SymfonyProcessRunner registers each
 * child for exactly as long as it runs.
 *
 * @internal
 */
final class RunningProcesses
{
    /** How often a wait re-checks whether the processes it is waiting on are gone. */
    private const int POLL_MICROSECONDS = 10_000;

    /**
     * SIGKILL is delivered asynchronously: posix_kill() returns before the
     * process has gone. A process in uninterruptible sleep can take a while,
     * so the wait for it is bounded.
     */
    private const float KILL_WAIT_SECONDS = 2.0;

    /** @var array<int, Process> keyed by object id */
    private array $processes = [];

    public function __construct(private readonly ProcessTree $tree = new ProcessTree())
    {
    }

    public function add(Process $process): void
    {
        $this->processes[spl_object_id($process)] = $process;
    }

    public function remove(Process $process): void
    {
        unset($this->processes[spl_object_id($process)]);
    }

    /**
     * Stop every registered child that is still running and everything below
     * it, then forget them all. A tool's own children (PHPStan's workers,
     * paratest's runners) outlive it otherwise, so the whole tree is read
     * first, while it can still be traced from the child, and sent SIGTERM;
     * whatever outlives the grace period is sent SIGKILL, and is waited for,
     * so that on return nothing is left to hold the run's resources.
     *
     * @return int how many registered children were still running
     */
    public function stopAll(float $graceSeconds): int
    {
        $children        = array_values(array_filter($this->processes, static fn (Process $process): bool => $process->isRunning()));
        $this->processes = [];

        $descendants = [];
        foreach ($children as $child) {
            $pid = $child->getPid();
            if (null !== $pid) {
                $descendants = [...$descendants, ...$this->tree->descendantsOf($pid)];
            }
        }

        foreach ($children as $child) {
            $this->terminate($child);
        }

        foreach ($descendants as $pid) {
            $this->signal($pid, \SIGTERM);
        }

        $deadline = microtime(true) + $graceSeconds;
        while ($this->anyAlive($children, ...$descendants) && microtime(true) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        $killed = array_values(array_filter($descendants, $this->tree->isAlive(...)));
        foreach ($killed as $pid) {
            $this->signal($pid, \SIGKILL);
        }

        $deadline = microtime(true) + self::KILL_WAIT_SECONDS;
        while ($this->anyAlive([], ...$killed) && microtime(true) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        foreach ($children as $child) {
            // Already gone, so this only settles Symfony's view of it; a child
            // that outlived the grace period gets SIGKILL from Symfony here.
            $child->stop(0);
        }

        return \count($children);
    }

    /** @param list<Process> $children */
    private function anyAlive(array $children, int ...$descendants): bool
    {
        foreach ($children as $child) {
            if ($child->isRunning()) {
                return true;
            }
        }

        return array_any($descendants, fn (int $pid): bool => $this->tree->isAlive($pid));
    }

    /**
     * Through Symfony, so the Process knows the signal was its own and a run()
     * still waiting on it reports the exit rather than throwing
     * ProcessSignaledException. A child that exited a moment ago is fine.
     */
    private function terminate(Process $child): void
    {
        try {
            $child->signal(\SIGTERM);
        } catch (LogicException $logicException) {
            if ($child->isRunning()) {
                throw $logicException;
            }
        }
    }

    /**
     * A process that has exited since the tree was read cannot be signalled,
     * which is the outcome wanted; any other failure is real.
     */
    private function signal(int $pid, int $signal): void
    {
        try {
            \Safe\posix_kill($pid, $signal);
        } catch (PosixException $posixException) {
            if ($this->tree->isAlive($pid)) {
                throw $posixException;
            }
        }
    }
}
