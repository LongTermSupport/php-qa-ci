<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\ClockInterface;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Lock\SystemClock;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(RunLock::class)]
#[CoversClass(LockInfoDto::class)]
#[CoversClass(SystemClock::class)]
#[Small]
final class RunLockTest extends TestCase
{
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
    public function acquiringWritesTheLockFileAndAGitignoreUnderQaConfig(): void
    {
        $lock = RunLock::forProject($this->project->path, $this->output, $this->clockAt(1_000_000));

        self::assertTrue($lock->acquire('phpstan', 'src', 'host-a', 42));

        $info = $lock->current();
        self::assertNotNull($info);
        self::assertSame('host-a', $info->hostname);
        self::assertSame(42, $info->pid);
        self::assertSame('phpstan', $info->tool);
        self::assertSame('src', $info->path);
        self::assertSame(1_000_000, $info->startedAt);
        self::assertSame(1_000_000, $info->lastActivity);
        self::assertStringContainsString('never tracked', $this->project->read('qaConfig/.qa-lock/.gitignore'));
        self::assertStringContainsString('[QA Lock] Acquired', $this->output->fetch());
    }

    #[Test]
    public function aLiveLockIsRefusedWithTheHoldersDetails(): void
    {
        RunLock::forProject($this->project->path, $this->output, $this->clockAt(1_000_000))->acquire('unit', '', 'host-a', 42);
        $this->output->fetch();

        $second = RunLock::forProject($this->project->path, $this->output, $this->clockAt(1_000_000 + RunLock::STALE_AFTER_SECONDS - 1));

        self::assertFalse($second->acquire('stan', '', 'host-b', 43));
        $printed = $this->output->fetch();
        self::assertStringContainsString('Another QA run holds the lock', $printed);
        self::assertStringContainsString('host host-a, pid 42, tool "unit"', $printed);
        self::assertSame(42, $second->current()?->pid, 'the live lock is left in place');
    }

    #[Test]
    public function aStaleLockIsRemovedAndReplaced(): void
    {
        RunLock::forProject($this->project->path, $this->output, $this->clockAt(1_000_000))->acquire('unit', '', 'host-a', 42);
        $this->output->fetch();

        $second = RunLock::forProject($this->project->path, $this->output, $this->clockAt(1_000_000 + RunLock::STALE_AFTER_SECONDS));

        self::assertTrue($second->acquire('stan', '', 'host-b', 43));
        self::assertStringContainsString('Removing stale lock (no activity for 600s', $this->output->fetch());
        self::assertSame(43, $second->current()?->pid);
    }

    #[Test]
    public function touchUpdatesLastActivityOnly(): void
    {
        $clock = new MutableClock(1_000_000);
        $lock  = RunLock::forProject($this->project->path, $this->output, $clock);
        $lock->acquire('unit', '', 'h', 1);

        $clock->now = 1_000_300;
        $lock->touch();

        $info = $lock->current();
        self::assertNotNull($info);
        self::assertSame(1_000_000, $info->startedAt);
        self::assertSame(1_000_300, $info->lastActivity);
    }

    #[Test]
    public function releaseRemovesTheLockAndReportsTheDuration(): void
    {
        $clock = new MutableClock(1_000_000);
        $lock  = RunLock::forProject($this->project->path, $this->output, $clock);
        $lock->acquire('unit', '', 'h', 1);

        $clock->now = 1_000_125;

        $lock->release(1);

        self::assertNull($lock->current());
        self::assertStringContainsString('Released at', $this->output->fetch());
        self::assertFileDoesNotExist($lock->lockFile());
    }

    #[Test]
    public function touchAndReleaseWithoutALockAreNoOps(): void
    {
        $lock = RunLock::forProject($this->project->path, $this->output, $this->clockAt(1));

        $lock->touch();
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

    private function clockAt(int $now): ClockInterface
    {
        return new MutableClock($now);
    }
}

/**
 * @internal
 */
final class MutableClock implements ClockInterface
{
    public function __construct(public int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
