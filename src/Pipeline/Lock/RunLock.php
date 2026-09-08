<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One run at a time per project. A JSON lock file under qaConfig/.qa-lock/
 * records who holds it and when it last showed activity; a holder that has
 * been quiet for longer than the stale window is presumed dead (container
 * restarts leave PIDs meaningless, so liveness is time-based, not PID-based).
 *
 * @internal
 */
final readonly class RunLock
{
    public const int STALE_AFTER_SECONDS = 600;

    private const string LOCK_FILE = 'qa-running.lock';

    private const string GITIGNORE = "# QA lock directory: runtime state only, never tracked.\n*\n";

    public function __construct(
        private string $lockDir,
        private OutputInterface $output,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    public static function forProject(string $projectRoot, OutputInterface $output, ClockInterface $clock = new SystemClock()): self
    {
        return new self($projectRoot . '/qaConfig/.qa-lock', $output, $clock);
    }

    /**
     * Acquire the lock, or report the live holder and return false. A stale
     * lock is removed with a note and then acquired.
     */
    public function acquire(string $tool, string $path, string $hostname, int $pid): bool
    {
        if (!is_dir($this->lockDir)) {
            \Safe\mkdir($this->lockDir, 0o777, true);
        }

        $gitignore = $this->lockDir . '/.gitignore';
        if (!is_file($gitignore)) {
            \Safe\file_put_contents($gitignore, self::GITIGNORE);
        }

        $existing = $this->current();
        if ($existing instanceof LockInfoDto) {
            $idle = $this->clock->now() - $existing->lastActivity;
            if ($idle < self::STALE_AFTER_SECONDS) {
                $this->output->writeln('');
                $this->output->writeln('[QA Lock] Another QA run holds the lock:');
                $this->output->writeln(\sprintf('[QA Lock]   host %s, pid %d, tool "%s", path "%s"', $existing->hostname, $existing->pid, $existing->tool, $existing->path));
                $this->output->writeln(\sprintf('[QA Lock]   started %s, last activity %ds ago (stale after %ds)', date('H:i:s', $existing->startedAt), $idle, self::STALE_AFTER_SECONDS));
                $this->output->writeln('[QA Lock] Wait for it to finish, or remove ' . $this->lockFile() . ' if you are sure it is dead.');

                return false;
            }

            $this->output->writeln(\sprintf('[QA Lock] Removing stale lock (no activity for %ds, held by pid %d on %s).', $idle, $existing->pid, $existing->hostname));
            \Safe\unlink($this->lockFile());
        }

        $now = $this->clock->now();
        $this->write(new LockInfoDto($hostname, $pid, $tool, $path, $now, $now));
        $this->output->writeln(\sprintf('[QA Lock] Acquired at %s', date('H:i:s', $now)));

        return true;
    }

    /** Record activity so the lock does not go stale during a long tool. */
    public function touch(): void
    {
        $existing = $this->current();
        if (!$existing instanceof LockInfoDto) {
            return;
        }

        $this->write(new LockInfoDto($existing->hostname, $existing->pid, $existing->tool, $existing->path, $existing->startedAt, $this->clock->now()));
    }

    public function release(int $exitCode): void
    {
        $existing = $this->current();
        if (!$existing instanceof LockInfoDto) {
            return;
        }

        \Safe\unlink($this->lockFile());
        $elapsed = $this->clock->now() - $existing->startedAt;
        $this->output->writeln('');
        $this->output->writeln(\sprintf('[QA Lock] Released at %s (exit %d)', date('H:i:s', $this->clock->now()), $exitCode));
        $this->output->writeln(\sprintf('[QA Lock] Execution took %s', self::formatDuration($elapsed)));
    }

    public function current(): ?LockInfoDto
    {
        $file = $this->lockFile();
        if (!is_file($file)) {
            return null;
        }

        return LockInfoDto::fromJson(\Safe\file_get_contents($file));
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

    private function write(LockInfoDto $info): void
    {
        \Safe\file_put_contents($this->lockFile(), $info->toJson());
    }
}
