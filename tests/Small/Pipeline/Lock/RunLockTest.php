<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\ClockInterface;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Lock\SystemClock;
use LTS\PHPQA\Tests\Support\MutableClock;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Two RunLock instances in one process open the lock file separately, so their
 * flocks contend exactly as two processes' would. Each holder is kept in a
 * variable for as long as it must hold: a RunLock that is destroyed closes its
 * file, and the kernel drops the flock with it.
 *
 * @internal
 */
#[CoversClass(RunLock::class)]
#[CoversClass(LockInfoDto::class)]
#[CoversClass(SystemClock::class)]
#[Small]
final class RunLockTest extends TestCase
{
    private const string HOST_A = 'host-a';

    private const string HOST_B = 'host-b';

    private const string TOOL_UNIT = 'unit';

    private const string TOOL_STAN = 'stan';

    private const string LOCK_FILE = 'qaConfig/.qa-lock/qa-running.lock';

    private const string LOCK_DIR = '/qaConfig/.qa-lock';

    private TempDir $project;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-lock');
        $this->output  = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function acquiringWritesTheHolderRecordAndAGitignoreUnderQaConfig(): void
    {
        $lock = $this->lockAt(1_000_000);

        self::assertTrue($lock->acquire('phpstan', 'src', self::HOST_A, 42));

        $info = $lock->current();
        self::assertNotNull($info);
        self::assertSame(self::HOST_A, $info->hostname);
        self::assertSame(42, $info->pid);
        self::assertSame('phpstan', $info->tool);
        self::assertSame('src', $info->path);
        self::assertSame(1_000_000, $info->startedAt);
        self::assertStringContainsString('never tracked', $this->project->read('qaConfig/.qa-lock/.gitignore'));
        self::assertStringContainsString('[QA Lock] Acquired', $this->output->fetch());
    }

    #[Test]
    public function aHeldLockIsRefusedWithTheHoldersPidAndStartTime(): void
    {
        $holder = $this->lockAt(1_000_000);
        $holder->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);

        $this->output->fetch();

        $second = $this->lockAt(1_000_000 + 86_400);

        self::assertFalse($second->acquire(self::TOOL_STAN, '', self::HOST_B, 43), 'a holder is live for as long as it holds the flock, however long that is');
        $printed = $this->output->fetch();
        self::assertStringContainsString('Another QA run holds the lock', $printed);
        self::assertStringContainsString('host host-a, pid 42, tool "unit"', $printed);
        self::assertStringContainsString('started ' . date('H:i:s', 1_000_000), $printed);
        self::assertSame(42, $second->current()?->pid, 'the holder record is left in place');
    }

    #[Test]
    public function aHolderThatHasNotWrittenItsRecordYetIsStillRefused(): void
    {
        $holder = $this->flockDirectly();

        self::assertFalse($this->lockAt(1)->acquire(self::TOOL_STAN, '', self::HOST_B, 43));
        self::assertStringContainsString('holder details not written yet', $this->output->fetch());

        \Safe\fclose($holder);
    }

    #[Test]
    public function aRecordLeftByARunThatDiedIsTakenOverWithANote(): void
    {
        $this->project->write(self::LOCK_FILE, new LockInfoDto(self::HOST_A, 42, self::TOOL_UNIT, '', 1_000_000)->toJson());

        $lock = $this->lockAt(1_000_010);

        self::assertTrue($lock->acquire(self::TOOL_STAN, '', self::HOST_B, 43), 'nobody holds the flock, so the record is history, not a holder');
        $printed = $this->output->fetch();
        self::assertStringContainsString('ended without releasing the lock', $printed);
        self::assertStringContainsString('host host-a, pid 42', $printed);
        self::assertSame(43, $lock->current()?->pid);
    }

    #[Test]
    public function anUnreadableRecordNeverBlocksTheNextRun(): void
    {
        $this->project->write(self::LOCK_FILE, '{"half a record');

        $lock = $this->lockAt(1);

        self::assertTrue($lock->acquire(self::TOOL_STAN, '', self::HOST_B, 43));
        self::assertStringContainsString('unreadable holder record', $this->output->fetch());
        self::assertSame(43, $lock->current()?->pid);
    }

    #[Test]
    public function releaseFreesTheLockAndReportsTheDuration(): void
    {
        $clock = new MutableClock(1_000_000);
        $lock  = RunLock::forProject($this->project->path, $this->output, $clock);
        $lock->acquire(self::TOOL_UNIT, '', 'h', 1);

        $clock->now = 1_000_125;

        $lock->release(1);

        self::assertNull($lock->current(), 'the holder record is cleared');
        $printed = $this->output->fetch();
        self::assertStringContainsString('Released at', $printed);
        self::assertStringContainsString('(exit 1)', $printed);
        self::assertStringContainsString('2 minutes 5 seconds', $printed);

        $next = $this->lockAt(1_000_200);
        self::assertTrue($next->acquire(self::TOOL_STAN, '', 'h', 2));
        self::assertStringNotContainsString('ended without releasing', $this->output->fetch(), 'a clean release leaves nothing to take over');
    }

    #[Test]
    public function releaseIsIdempotentSoTheSignalHandlerAndFinallyCanBothCallIt(): void
    {
        $lock = $this->lockAt(1);
        $lock->acquire(self::TOOL_UNIT, '', 'h', 1);

        $this->output->fetch();

        $lock->release(143);
        $lock->release(1);

        self::assertSame(1, substr_count($this->output->fetch(), 'Released at'));
    }

    #[Test]
    public function releaseByARunThatNeverAcquiredLeavesTheHolderAlone(): void
    {
        $holder = $this->lockAt(1);
        $holder->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);

        $contender = $this->lockAt(2);
        $contender->acquire(self::TOOL_STAN, '', self::HOST_B, 43);

        $this->output->fetch();

        $contender->release(130);

        self::assertSame('', $this->output->fetch());
        self::assertSame(42, $contender->current()?->pid);
        self::assertFalse($this->lockAt(3)->acquire(self::TOOL_STAN, '', 'host-c', 44), 'the holder still holds the flock');
    }

    #[Test]
    public function releaseWithoutALockIsANoOp(): void
    {
        $lock = $this->lockAt(1);

        $lock->release(0);

        self::assertNull($lock->current());
        self::assertSame('', $this->output->fetch());
    }

    #[Test]
    public function durationsAreHumanReadable(): void
    {
        self::assertSame('45 seconds', RunLock::formatDuration(45));
        self::assertSame('3 minutes 27 seconds', RunLock::formatDuration(207));
        self::assertSame('2 hours 5 minutes', RunLock::formatDuration(7_500));
    }

    #[Test]
    public function aCorruptLockFileIsRejectedAndMissingKeysDefault(): void
    {
        $info = LockInfoDto::fromJson('{"hostname": "h"}');

        self::assertSame('h', $info->hostname);
        self::assertSame(0, $info->pid);
        self::assertSame('', $info->tool);

        $this->expectException(RuntimeException::class);
        LockInfoDto::fromJson('"just a string"');
    }

    #[Test]
    public function theSystemClockReadsTheWallClock(): void
    {
        self::assertEqualsWithDelta(time(), new SystemClock()->now(), 2);
    }

    #[Test]
    public function aContenderWaitsForTheHolderAndTakesTheLockWhenItIsReleased(): void
    {
        $holder = $this->lockAt(1_000_000);
        $holder->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);

        $this->output->fetch();

        $clock     = new MutableClock(1_000_000);
        $sleeps    = [];
        $contender = new RunLock(
            $this->project->path . self::LOCK_DIR,
            $this->output,
            $clock,
            maxWaitSeconds: 60,
            sleeper: static function (int $seconds) use ($clock, $holder, &$sleeps): void {
                $sleeps[] = $seconds;
                $clock->now += $seconds;
                if (3 === \count($sleeps)) {
                    $holder->release(0);
                }
            },
        );

        self::assertTrue($contender->acquire(self::TOOL_STAN, '', self::HOST_B, 43));
        self::assertCount(3, $sleeps);
        $printed = $this->output->fetch();
        self::assertStringContainsString('Waiting for the QA lock (up to 60 seconds)', $printed);
        self::assertStringContainsString('host host-a, pid 42, tool "unit"', $printed);
        self::assertSame(1, substr_count($printed, 'Waiting for the QA lock'), 'announced once, not on every poll');
        self::assertSame(43, $contender->current()?->pid);
    }

    #[Test]
    public function theWaitingNoticeIsOneLineOnTheNoticeOutputWhenOneIsGiven(): void
    {
        $holder = $this->lockAt(1_000_000);
        $holder->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);

        $this->output->fetch();

        $clock  = new MutableClock(1_000_000);
        $notice = new BufferedOutput();
        $quiet  = new BufferedOutput();
        $lock   = new RunLock(
            $this->project->path . self::LOCK_DIR,
            $quiet,
            $clock,
            maxWaitSeconds: 2,
            sleeper: static function (int $seconds) use ($clock): void {
                $clock->now += $seconds;
            },
            waitNotice: $notice,
        );

        self::assertFalse($lock->acquire(self::TOOL_STAN, '', self::HOST_B, 43));
        $line = $notice->fetch();
        self::assertSame(1, substr_count($line, "\n"), 'one line');
        self::assertStringContainsString('Waiting for the QA lock (up to 2 seconds)', $line);
        self::assertStringContainsString('host host-a, pid 42', $line);
        self::assertStringNotContainsString('Waiting for the QA lock', $quiet->fetch(), 'the banner output does not get it twice');
    }

    #[Test]
    public function aFailedGitignoreRenameLeavesNoStagingFileBehind(): void
    {
        $this->project->mkdir('qaConfig/.qa-lock/.gitignore');

        $failure = null;
        // The rename's own PHP warning is the expected failure here; Safe turns it into the exception.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $this->lockAt(1)->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);
        } catch (FilesystemException $filesystemException) {
            $failure = $filesystemException;
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(FilesystemException::class, $failure, 'the rename onto a directory fails');
        self::assertSame([], \Safe\glob($this->project->path . self::LOCK_DIR . '/.gitignore.*'));
    }

    #[Test]
    public function aContenderGivesUpWhenTheWaitExpires(): void
    {
        $holder = $this->lockAt(1_000_000);
        $holder->acquire(self::TOOL_UNIT, '', self::HOST_A, 42);

        $this->output->fetch();

        $clock     = new MutableClock(1_000_000);
        $contender = new RunLock(
            $this->project->path . self::LOCK_DIR,
            $this->output,
            $clock,
            maxWaitSeconds: 5,
            sleeper: static function (int $seconds) use ($clock): void {
                $clock->now += $seconds;
            },
        );

        self::assertFalse($contender->acquire(self::TOOL_STAN, '', self::HOST_B, 43));
        $printed = $this->output->fetch();
        self::assertStringContainsString('Gave up waiting after 5 seconds', $printed);
        self::assertStringContainsString('Another QA run holds the lock', $printed);
        self::assertSame(42, $contender->current()?->pid, 'the holder record is left in place');
    }

    private function lockAt(int $now): RunLock
    {
        return RunLock::forProject($this->project->path, $this->output, $this->clockAt($now));
    }

    private function clockAt(int $now): ClockInterface
    {
        return new MutableClock($now);
    }

    /**
     * Another process's view of the lock between taking the flock and writing
     * its record: held, and empty.
     *
     * @return resource
     */
    private function flockDirectly(): mixed
    {
        $this->project->mkdir('qaConfig/.qa-lock');
        $handle = \Safe\fopen($this->project->path . '/' . self::LOCK_FILE, 'c+');
        \Safe\flock($handle, \LOCK_EX | \LOCK_NB);

        return $handle;
    }
}
