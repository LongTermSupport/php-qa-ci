<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use Closure;
use Iterator;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Lock\SystemClock;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Runner\RunInterruptHandler;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The handler's terminate step is injected so a test can watch the exit code
 * instead of losing the test runner to it. Child processes are covered with
 * real pids in RunningProcessesTest and end to end in RunLockSignalTest.
 *
 * @internal
 */
#[CoversClass(RunInterruptHandler::class)]
#[UsesClass(RunLock::class)]
#[UsesClass(LockInfoDto::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(RunningProcesses::class)]
#[Small]
final class RunInterruptHandlerTest extends TestCase
{
    private TempDir $project;

    private BufferedOutput $output;

    private RunLock $lock;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-interrupt');
        $this->output  = new BufferedOutput();
        $this->lock    = RunLock::forProject($this->project->path, $this->output);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    #[DataProvider('signals')]
    public function aSignalReleasesTheLockAndTerminatesWithTheConventionalCode(int $signal, string $name, int $exitCode): void
    {
        $this->lock->acquire('phpstan', '', 'h', 1);

        $terminated = $this->terminateFor($signal, $this->handler($this->output));

        self::assertSame($exitCode, $terminated);
        self::assertNull($this->lock->current(), 'the holder record is cleared');
        self::assertTrue(RunLock::forProject($this->project->path, new BufferedOutput())->acquire('next', '', 'h', 2), 'the flock is free');
        $printed = $this->output->fetch();
        self::assertStringContainsString('[QA] Interrupted by ' . $name, $printed);
        self::assertStringContainsString(\sprintf('(exit %d)', $exitCode), $printed, 'the release reports the exit code the run ends with');
    }

    /** @return Iterator<string, array{int, string, int}> */
    public static function signals(): Iterator
    {
        yield 'Ctrl-C'         => [\SIGINT, 'SIGINT', 130];
        yield 'kill / TaskStop' => [\SIGTERM, 'SIGTERM', 143];
        yield 'terminal closed' => [\SIGHUP, 'SIGHUP', 129];
        yield 'Ctrl-\\'        => [\SIGQUIT, 'SIGQUIT', 131];
    }

    #[Test]
    public function aSignalBeforeTheLockWasTakenStillTerminatesAndFreesNothing(): void
    {
        $holder = RunLock::forProject($this->project->path, new BufferedOutput());
        $holder->acquire('other run', '', 'h', 7);

        self::assertSame(143, $this->terminateFor(\SIGTERM, $this->handler($this->output)));
        self::assertSame(7, $this->lock->current()?->pid, 'a run that never acquired must not free the holder');
    }

    #[Test]
    public function theLockIsReleasedAndTheRunTerminatedEvenWhenTheConsoleIsGone(): void
    {
        $this->lock = RunLock::forProject($this->project->path, new BufferedOutput());
        $this->lock->acquire('phpstan', '', 'h', 1);

        self::assertSame(129, $this->terminateFor(\SIGHUP, $this->handler(new BrokenOutput())));
        self::assertNull($this->lock->current());
    }

    #[Test]
    public function installHandsEverySignalToTheHandlerAndUninstallRestoresWhatWasThere(): void
    {
        $before  = array_map(pcntl_signal_get_handler(...), RunInterruptHandler::SIGNALS);
        $async   = pcntl_async_signals();
        $handler = $this->handler($this->output);

        $handler->install();

        try {
            self::assertTrue(pcntl_async_signals(), 'a signal must interrupt a blocking wait on a child, not wait for a tick');
            foreach (RunInterruptHandler::SIGNALS as $signal) {
                self::assertInstanceOf(Closure::class, pcntl_signal_get_handler($signal));
            }
        } finally {
            $handler->uninstall();
        }

        self::assertSame($before, array_map(pcntl_signal_get_handler(...), RunInterruptHandler::SIGNALS));
        self::assertSame($async, pcntl_async_signals());
    }

    private function handler(OutputInterface $output): RunInterruptHandler
    {
        return new RunInterruptHandler(
            $this->lock,
            new RunningProcesses(),
            $output,
            static function (int $exitCode): never {
                throw new TerminatedException($exitCode);
            },
        );
    }

    private function terminateFor(int $signal, RunInterruptHandler $handler): int
    {
        try {
            $handler->handle($signal);
        } catch (TerminatedException $terminatedException) {
            return $terminatedException->exitCode;
        }

        self::fail('the handler must always terminate the run');
    }
}

/**
 * @internal
 */
final class TerminatedException extends RuntimeException
{
    public function __construct(public readonly int $exitCode)
    {
        parent::__construct('terminated with ' . $exitCode);
    }
}

/**
 * A console whose terminal has gone, as after SIGHUP: every write fails.
 *
 * @internal
 */
final class BrokenOutput extends BufferedOutput
{
    protected function doWrite(string $message, bool $newline): void
    {
        throw new RuntimeException('Unable to write output.');
    }
}
