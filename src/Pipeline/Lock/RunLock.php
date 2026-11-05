<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lock;

use Closure;
use JsonException;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use RuntimeException;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One run at a time per repository: an exclusive flock on qa-running.lock in
 * the lock directory (LockDirectoryResolver), held for the life of the run. The lock
 * file's JSON names the holder for a human; the flock is the lock. Liveness is
 * "can the flock be taken", never a pid or a clock.
 *
 * Two mechanisms, two concerns. RunInterruptHandler releases the lock
 * gracefully (and stops the child tools) on a catchable signal; the flock is
 * the guarantee for every death no handler sees (SIGKILL, the OOM killer, a
 * container stop), because the kernel drops it with the process.
 *
 * @internal
 */
final class RunLock
{
    private const string TIME_FORMAT = 'H:i:s';

    private const string LOCK_FILE = 'qa-running.lock';

    private const string GITIGNORE = "# QA lock directory: runtime state only, never tracked.\n*\n";

    private const string OPEN_MODE = 'c+e';

    private const int POLL_SECONDS = 1;

    /** @var resource|null the open lock file while this run holds the flock */
    private mixed $handle = null;

    public function __construct(
        private readonly string $lockDir,
        private readonly OutputInterface $output,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly int $maxWaitSeconds = 0,
        private readonly ?Closure $sleeper = null,
        private readonly ?OutputInterface $waitNotice = null,
    ) {
    }

    public static function forProject(string $projectRoot, OutputInterface $output, ClockInterface $clock = new SystemClock()): self
    {
        return new self($projectRoot . '/qaConfig/.qa-lock', $output, $clock);
    }

    /**
     * Take the flock, waiting up to the configured maximum for a holder to
     * finish, or report the holder and return false. The wait polls the flock;
     * the holder's liveness is never judged by a pid or a clock. A record left
     * by a run that died without releasing is noted and overwritten. The
     * one-line waiting notice goes to $waitNotice when given (agent mode, whose
     * ordinary banners are discarded), else to the banner output.
     */
    public function acquire(string $tool, string $path, string $hostname, int $pid): bool
    {
        $this->prepareDirectory();

        // 'c' creates without truncating, so a contender can read the holder's
        // record. 'e' is close-on-exec: a child tool that inherited the
        // descriptor would keep the flock alive after this process died.
        $handle    = \Safe\fopen($this->lockFile(), self::OPEN_MODE);
        $deadline  = $this->clock->now() + $this->maxWaitSeconds;
        $announced = false;
        while (!$this->flock($handle)) {
            if ($this->clock->now() >= $deadline) {
                \Safe\fclose($handle);
                $this->reportHolder();

                return false;
            }

            if (!$announced) {
                $this->reportWaiting();
                $announced = true;
            }

            $this->pause();
        }

        $this->handle = $handle;
        $this->reportAbandonedRecord();

        $now = $this->clock->now();
        \Safe\ftruncate($handle, 0);
        \Safe\rewind($handle);
        \Safe\fwrite($handle, new LockInfoDto($hostname, $pid, $tool, $path, $now)->toJson());
        \Safe\fflush($handle);
        $this->output->writeln(\sprintf('[QA Lock] Acquired at %s', date(self::TIME_FORMAT, $now)));

        return true;
    }

    /**
     * Clear the record and drop the flock. Idempotent, and a no-op for a run
     * that never acquired, so the interrupt handler and the pipeline's
     * `finally` can both call it and a contender can never free a holder's
     * lock. The lock is freed before anything is printed.
     */
    public function release(int $exitCode): void
    {
        $handle = $this->handle;
        if (null === $handle) {
            return;
        }

        $this->handle = null;
        $holder       = $this->current();
        \Safe\ftruncate($handle, 0);
        \Safe\flock($handle, \LOCK_UN);
        \Safe\fclose($handle);

        $now = $this->clock->now();
        $this->output->writeln('');
        $this->output->writeln(\sprintf('[QA Lock] Released at %s (exit %d)', date(self::TIME_FORMAT, $now), $exitCode));
        if ($holder instanceof LockInfoDto) {
            $this->output->writeln(\sprintf('[QA Lock] Execution took %s', self::formatDuration($now - $holder->startedAt)));
        }
    }

    /** The holder record, or null when there is none (or none written yet). */
    public function current(): ?LockInfoDto
    {
        $file = $this->lockFile();
        if (!is_file($file)) {
            return null;
        }

        $json = \Safe\file_get_contents($file);

        return '' === $json ? null : LockInfoDto::fromJson($json);
    }

    public function lockFile(): string
    {
        return $this->lockDir . '/' . self::LOCK_FILE;
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }

        $minutes = intdiv($seconds, 60);
        $rest    = $seconds % 60;
        if ($minutes < 60) {
            return \sprintf('%d minutes %d seconds', $minutes, $rest);
        }

        return \sprintf('%d hours %d minutes', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Non-blocking exclusive flock: false when another open file holds it,
     * any other failure thrown.
     *
     * @param resource $handle
     */
    private function flock(mixed $handle): bool
    {
        $wouldBlock = 0;

        try {
            \Safe\flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock);
        } catch (FilesystemException $filesystemException) {
            if (1 === $wouldBlock) {
                return false;
            }

            throw $filesystemException;
        }

        return true;
    }

    /**
     * Create the lock directory and its .gitignore. Runs that start together
     * all find neither, so both steps tolerate a rival having made them in the
     * meantime: a failed mkdir is an error only if the directory is still
     * missing, and the .gitignore is renamed into place whole, never half-written.
     */
    private function prepareDirectory(): void
    {
        if (!is_dir($this->lockDir)) {
            try {
                \Safe\mkdir($this->lockDir, 0o777, true);
            } catch (FilesystemException $filesystemException) {
                if (!is_dir($this->lockDir)) {
                    throw $filesystemException;
                }
            }
        }

        $gitignore = $this->lockDir . '/.gitignore';
        if (is_file($gitignore)) {
            return;
        }

        $staging = $gitignore . '.' . bin2hex(random_bytes(4));
        \Safe\file_put_contents($staging, self::GITIGNORE);
        \Safe\rename($staging, $gitignore);
    }

    private function reportHolder(): void
    {
        $this->output->writeln('');
        if ($this->maxWaitSeconds > 0) {
            $this->output->writeln(\sprintf('[QA Lock] Gave up waiting after %d seconds.', $this->maxWaitSeconds));
        }

        $this->output->writeln('[QA Lock] Another QA run holds the lock:');
        $this->output->writeln('[QA Lock]   ' . $this->describeHolder());
        $this->output->writeln('[QA Lock] Wait for it to finish. The lock is an flock, so it frees itself however that run ends.');
    }

    private function reportWaiting(): void
    {
        ($this->waitNotice ?? $this->output)->writeln(\sprintf(
            '[QA Lock] Waiting for the QA lock (up to %d seconds); another QA run holds it: %s',
            $this->maxWaitSeconds,
            $this->describeHolder(),
        ));
    }

    private function describeHolder(): string
    {
        return $this->describeRecord('holder details not written yet', static fn (LockInfoDto $holder): string => \sprintf(
            '%s, started %s',
            $holder->describe(),
            date(self::TIME_FORMAT, $holder->startedAt),
        ));
    }

    private function pause(): void
    {
        if ($this->sleeper instanceof Closure) {
            ($this->sleeper)(self::POLL_SECONDS);

            return;
        }

        \Safe\sleep(self::POLL_SECONDS);
    }

    private function reportAbandonedRecord(): void
    {
        if ('' === \Safe\file_get_contents($this->lockFile())) {
            return;
        }

        $this->output->writeln('[QA Lock] A previous run ended without releasing the lock (killed?); taking it over from ' . $this->describeRecord('', static fn (LockInfoDto $holder): string => $holder->describe()));
    }

    /**
     * The record as one line for a banner. The record is information only, so
     * an unreadable one is described, never fatal: a half-written record from
     * a run killed mid-write must not block the runs after it.
     *
     * @param callable(LockInfoDto): string $describe
     */
    private function describeRecord(string $whenEmpty, callable $describe): string
    {
        try {
            $holder = $this->current();
        } catch (JsonException|RuntimeException $unreadable) {
            return \sprintf('an unreadable holder record in %s (%s)', $this->lockFile(), $unreadable->getMessage());
        }

        return $holder instanceof LockInfoDto ? $describe($holder) : $whenEmpty;
    }
}
