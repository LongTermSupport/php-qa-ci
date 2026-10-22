<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\StopOnFirstWriteOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\PosixException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Real child processes: what an interrupted run has to stop is a real pid.
 *
 * @internal
 */
#[CoversClass(RunningProcesses::class)]
#[CoversClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(ProcessTree::class)]
#[Large]
final class RunningProcessesTest extends TestCase
{
    private const string SLEEP = 'sleep';

    private const string FOREVER = '60';

    private const string EXEC_SLEEP = 'exec sleep 60';

    private const string DESCENDANT = 'descendant ';

    #[Test]
    public function stopAllTerminatesEveryRegisteredRunningProcess(): void
    {
        $running = new RunningProcesses();
        $first   = $this->sleeper();
        $second  = $this->sleeper();

        try {
            $first->start();
            $running->add($first);
            $second->start();
            $running->add($second);

            self::assertSame(2, $running->stopAll(5.0));

            self::assertFalse($first->isRunning());
            self::assertFalse($second->isRunning());
            self::assertSame(143, $first->getExitCode(), 'stopped by SIGTERM (128 + 15)');
        } finally {
            $first->stop(0);
            $second->stop(0);
        }
    }

    /**
     * PHPStan's parallel workers, paratest's runners: a tool's own children
     * outlive it unless they are stopped too. The tree is read before anything
     * is signalled, because once the tool dies they are reparented and can no
     * longer be found by their parent.
     */
    #[Test]
    public function stopAllTakesTheChildsOwnDescendantsWithIt(): void
    {
        $running     = new RunningProcesses();
        $tool        = $this->toolWithDescendants(self::EXEC_SLEEP);
        $descendants = [];

        try {
            $tool->start();
            $running->add($tool);
            $descendants = $this->reportedDescendants($tool);

            $started = microtime(true);
            self::assertSame(1, $running->stopAll(5.0));

            self::assertLessThan(2.5, microtime(true) - $started, 'all of them obey SIGTERM, so nothing waits out the grace period');
            self::assertFalse($tool->isRunning());
            foreach ($descendants as $pid) {
                self::assertFalse(new ProcessTree()->isAlive($pid), self::DESCENDANT . $pid . ' was left running');
            }
        } finally {
            $tool->stop(0);
            $this->killLeftovers(...$descendants);
        }
    }

    /**
     * The grace period is an upper bound, not a wait: once the child and every
     * descendant have gone, stopAll returns. The child itself is ended by the
     * SIGTERM sent through Symfony, so it reports 128 + 15.
     */
    #[Test]
    public function stopAllReturnsAsSoonAsTheChildAndItsDescendantsHaveGone(): void
    {
        $running     = new RunningProcesses();
        $tool        = $this->toolWithDescendants(self::EXEC_SLEEP);
        $descendants = [];

        try {
            $tool->start();
            $running->add($tool);
            $descendants = $this->reportedDescendants($tool);

            $started = microtime(true);
            self::assertSame(1, $running->stopAll(5.0));
            $elapsed = microtime(true) - $started;

            self::assertLessThan(2.5, $elapsed, 'nothing was left alive, so nothing waited out the grace period');
            self::assertSame(143, $tool->getExitCode(), 'the child was sent SIGTERM itself');
        } finally {
            $tool->stop(0);
            $this->killLeftovers(...$descendants);
        }
    }

    /** The tree of every registered child is read, not only the last one's. */
    #[Test]
    public function stopAllTakesTheDescendantsOfEveryChild(): void
    {
        $running     = new RunningProcesses();
        $first       = $this->toolWithDescendants(self::EXEC_SLEEP);
        $second      = $this->toolWithDescendants(self::EXEC_SLEEP);
        $descendants = [];

        try {
            $first->start();
            $running->add($first);
            $second->start();
            $running->add($second);
            $descendants = [...$this->reportedDescendants($first), ...$this->reportedDescendants($second)];

            self::assertSame(2, $running->stopAll(5.0));

            foreach ($descendants as $pid) {
                self::assertFalse(new ProcessTree()->isAlive($pid), self::DESCENDANT . $pid . ' was left running');
            }
        } finally {
            $first->stop(0);
            $second->stop(0);
            $this->killLeftovers(...$descendants);
        }
    }

    /**
     * A child that ignores SIGTERM is waited for, without spinning, for the whole
     * grace period, then killed at once: Symfony is not given a grace of its own.
     */
    #[Test]
    public function aChildThatIgnoresSigtermIsGivenTheGracePeriodThenKilled(): void
    {
        $running = new RunningProcesses();
        $child   = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'pcntl_signal(SIGTERM, SIG_IGN); echo "ready\n"; fflush(STDOUT); sleep(60);'],
            null,
            ['XDEBUG_MODE' => 'off'],
        );

        try {
            $child->start();
            $child->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
            $running->add($child);

            $cpu     = $this->cpuSeconds();
            $started = microtime(true);
            self::assertSame(1, $running->stopAll(0.5));
            $elapsed = microtime(true) - $started;

            self::assertGreaterThanOrEqual(0.5, $elapsed, 'it was given the grace period');
            self::assertLessThan(1.2, $elapsed, 'and then killed, not given a second grace period');
            self::assertLessThan(0.25, $this->cpuSeconds() - $cpu, 'the wait polls; it does not spin');
            self::assertFalse($child->isRunning(), 'it was killed once the grace period was up');
        } finally {
            $child->stop(0);
        }
    }

    #[Test]
    public function aDescendantThatIgnoresSigtermIsKilledOnceTheGracePeriodIsUp(): void
    {
        $running     = new RunningProcesses();
        $tool        = $this->toolWithDescendants('trap \"\" TERM; exec sleep 60');
        $descendants = [];

        try {
            $tool->start();
            $running->add($tool);
            $descendants = $this->reportedDescendants($tool);

            $started = microtime(true);
            $running->stopAll(0.5);

            self::assertGreaterThanOrEqual(0.5, microtime(true) - $started, 'it was given the grace period first');
            foreach ($descendants as $pid) {
                self::assertFalse(new ProcessTree()->isAlive($pid), self::DESCENDANT . $pid . ' survived SIGKILL');
            }
        } finally {
            $tool->stop(0);
            $this->killLeftovers(...$descendants);
        }
    }

    /**
     * SIGKILL goes only to the descendants the tree still reports alive once
     * the grace period is up: a pid that has gone may already belong to an
     * unrelated process. The tree here is a fake /proc naming a real process
     * that ignores SIGTERM as the child's descendant, in a state (zombie) the
     * tree counts as gone, so a SIGKILL sent regardless would end it.
     */
    #[Test]
    public function aDescendantTheTreeReportsGoneIsNotSentSigkill(): void
    {
        $proc      = \Safe\tempnam(sys_get_temp_dir(), 'fake-proc-');
        $child     = $this->sleeper();
        $bystander = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'pcntl_signal(SIGTERM, SIG_IGN); echo "ready\n"; fflush(STDOUT); sleep(60);'],
            null,
            ['XDEBUG_MODE' => 'off'],
        );
        $statDir  = '';
        $statFile = '';

        try {
            \Safe\unlink($proc);
            \Safe\mkdir($proc);
            $child->start();
            $bystander->start();
            $bystander->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
            $bystanderPid = $bystander->getPid();
            $childPid     = $child->getPid();
            self::assertIsInt($bystanderPid);
            self::assertIsInt($childPid);
            $statDir = $proc . '/' . $bystanderPid;
            \Safe\mkdir($statDir);
            $statFile = $statDir . '/stat';
            \Safe\file_put_contents($statFile, \sprintf("%d (php) Z %d 0 0\n", $bystanderPid, $childPid));

            $running = new RunningProcesses(new ProcessTree($proc));
            $running->add($child);

            self::assertSame(1, $running->stopAll(5.0));
            self::assertSame(143, $child->getExitCode(), 'the child itself was stopped by SIGTERM');
            // SIGKILL lands asynchronously, so give one sent in error the time to take effect.
            $deadline = microtime(true) + 0.5;
            while ($bystander->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }

            self::assertTrue($bystander->isRunning(), 'a descendant the tree reports gone was sent SIGKILL');
        } finally {
            $child->stop(0);
            $bystander->stop(0);
            if ('' !== $statFile && is_file($statFile)) {
                \Safe\unlink($statFile);
            }

            if ('' !== $statDir && is_dir($statDir)) {
                \Safe\rmdir($statDir);
            }

            if (is_dir($proc)) {
                \Safe\rmdir($proc);
            }
        }
    }

    #[Test]
    public function aRemovedOrFinishedProcessIsNotCounted(): void
    {
        $running  = new RunningProcesses();
        $removed  = $this->sleeper();
        $finished = new Process(['true']);

        try {
            $removed->start();
            $running->add($removed);
            $running->add($finished);
            $finished->run();
            $running->remove($removed);

            self::assertSame(0, $running->stopAll(5.0));
            self::assertTrue($removed->isRunning(), "a process nobody registered is not this registry's to stop");
        } finally {
            $removed->stop(0);
        }
    }

    #[Test]
    public function stopAllEmptiesTheRegistry(): void
    {
        $running = new RunningProcesses();
        $process = $this->sleeper();

        try {
            $process->start();
            $running->add($process);

            $running->stopAll(5.0);

            self::assertSame(0, $running->stopAll(5.0));
        } finally {
            $process->stop(0);
        }
    }

    /**
     * The runner registers the child for the whole of its run: a stop issued
     * while the child is still writing (which is when a signal arrives in
     * practice) reaches it, and the run returns the child's real exit.
     */
    #[Test]
    public function theRunnerRegistersItsChildForAsLongAsItRuns(): void
    {
        $running = new RunningProcesses();
        $output  = new StopOnFirstWriteOutput($running);
        $runner  = new SymfonyProcessRunner($output, $running);

        $result = $runner->run(new ProcessSpecDto(['sh', '-c', 'echo started; exec sleep 60'], sys_get_temp_dir()));

        self::assertSame(1, $output->stopped, 'the child was registered while it ran');
        self::assertSame(143, $result->exitCode, 'SIGTERM ended the child');
        self::assertSame(0, $running->stopAll(5.0), 'and it was deregistered when the run returned');
    }

    #[Test]
    public function theRunnerDeregistersAChildThatEndsOnItsOwn(): void
    {
        $running = new RunningProcesses();
        $runner  = new SymfonyProcessRunner(new BufferedOutput(), $running);

        $result = $runner->run(new ProcessSpecDto(['true'], sys_get_temp_dir(), streamOutput: false));

        self::assertSame(0, $result->exitCode);
        self::assertSame(0, $running->stopAll(5.0));
    }

    /**
     * A shell running $body in two background shells (one nested a level
     * deeper), each of which reports its pid before running it; a body that
     * execs keeps that pid, so a leftover can be killed by it. Not started:
     * the test starts it inside the try whose finally stops it.
     */
    private function toolWithDescendants(string $body): Process
    {
        $worker = \sprintf('sh -c "echo \$\$; %s"', $body);

        return new Process(['sh', '-c', \sprintf("%s & sh -c '%s & wait' & wait", $worker, $worker)]);
    }

    /** @return list<int> the pids the started tool's two descendants reported */
    private function reportedDescendants(Process $tool): array
    {
        $deadline = microtime(true) + 10;
        do {
            $pids = array_values(array_map(intval(...), array_filter(explode("\n", $tool->getOutput()), static fn (string $line): bool => '' !== $line)));
            if (2 === \count($pids)) {
                return $pids;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('the descendants never reported their pids: ' . $tool->getOutput() . $tool->getErrorOutput());
    }

    private function sleeper(): Process
    {
        return new Process([self::SLEEP, self::FOREVER]);
    }

    /** A failed assertion must not leave a descendant running past the test. */
    private function killLeftovers(int ...$pids): void
    {
        $tree = new ProcessTree();
        foreach ($pids as $pid) {
            if (!$tree->isAlive($pid)) {
                continue;
            }

            try {
                \Safe\posix_kill($pid, \SIGKILL);
            } catch (PosixException $posixException) {
                // It exited between the check and the kill; a failure here would hide the test's own.
                if ($tree->isAlive($pid)) {
                    throw $posixException;
                }
            }
        }
    }

    /** User and system CPU time this process has used so far. */
    private function cpuSeconds(): float
    {
        $usage = \Safe\getrusage();
        $total = 0.0;
        foreach (['ru_utime', 'ru_stime'] as $clock) {
            $seconds      = $usage[$clock . '.tv_sec']  ?? null;
            $microseconds = $usage[$clock . '.tv_usec'] ?? null;
            self::assertIsInt($seconds);
            self::assertIsInt($microseconds);
            $total += $seconds + $microseconds / 1_000_000;
        }

        return $total;
    }
}
