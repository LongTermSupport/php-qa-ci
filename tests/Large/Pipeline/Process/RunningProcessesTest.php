<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessTree;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
        $running = new RunningProcesses();
        $tool    = $this->toolWithDescendants('sleep 60');

        try {
            $tool->start();
            $running->add($tool);
            $descendants = $this->reportedDescendants($tool);

            self::assertSame(1, $running->stopAll(5.0));

            self::assertFalse($tool->isRunning());
            foreach ($descendants as $pid) {
                self::assertFalse(new ProcessTree()->isAlive($pid), 'descendant ' . $pid . ' was left running');
            }
        } finally {
            $tool->stop(0);
        }
    }

    #[Test]
    public function aDescendantThatIgnoresSigtermIsKilledOnceTheGracePeriodIsUp(): void
    {
        $running = new RunningProcesses();
        $tool    = $this->toolWithDescendants('trap \"\" TERM; sleep 60');

        try {
            $tool->start();
            $running->add($tool);
            $descendants = $this->reportedDescendants($tool);

            $started = microtime(true);
            $running->stopAll(0.5);

            self::assertGreaterThanOrEqual(0.5, microtime(true) - $started, 'it was given the grace period first');
            foreach ($descendants as $pid) {
                self::assertFalse(new ProcessTree()->isAlive($pid), 'descendant ' . $pid . ' survived SIGKILL');
            }
        } finally {
            $tool->stop(0);
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
     * deeper), each of which reports its pid before running it. Not started:
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
}

/**
 * Stands in for the interrupt handler: the first time the child writes, stop
 * everything that is running.
 *
 * @internal
 */
final class StopOnFirstWriteOutput extends BufferedOutput
{
    public int $stopped = 0;

    public function __construct(private readonly RunningProcesses $running)
    {
        parent::__construct();
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite($message, $newline);
        if (0 === $this->stopped && str_contains($message, 'started')) {
            $this->stopped = $this->running->stopAll(5.0);
        }
    }
}
