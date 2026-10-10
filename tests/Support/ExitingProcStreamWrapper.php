<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A process table in which every process exits after its stat file has been
 * opened and before it is read: the open succeeds, and the read then fails
 * as the kernel's does (a notice and no data) or comes back empty. That is
 * the moment inside ProcessTree's read, held still so a test can reach it
 * every time.
 *
 * Under the GONE_ schemes the process's own directory has gone by the time
 * anyone looks, so the process has exited. Under the RUNNING_ schemes it is
 * still there: the process is running, and the failed read is a real
 * failure.
 *
 * Registered by a test; the method names are PHP's stream wrapper protocol.
 * PHP's stream layer is what calls them, so the class is tagged as API: an
 * entry point to the dead-code detector, as a Composer plugin is.
 *
 * @api
 */
final class ExitingProcStreamWrapper
{
    /** The read fails with the kernel's notice; the process's directory has gone. */
    public const string GONE_FAILED_READ_SCHEME = 'exitedfailedproc';

    /** The read returns nothing; the process's directory has gone. */
    public const string GONE_EMPTY_READ_SCHEME = 'exitedemptyproc';

    /** The read fails with the kernel's notice; the process's directory is still there. */
    public const string RUNNING_FAILED_READ_SCHEME = 'runningfailedproc';

    /** The read returns nothing; the process's directory is still there. */
    public const string RUNNING_EMPTY_READ_SCHEME = 'runningemptyproc';

    /** What /proc/<pid>/stat's read raises once the process has exited. */
    public const string READ_NOTICE = 'read of 8192 bytes failed with errno=3 No such process';

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

    private bool $failsTheRead = false;

    private bool $read = false;

    /** @return array{mode: int, size: int}|false */
    public function url_stat(string $path): array|false
    {
        if (str_ends_with($path, '/stat')) {
            return ['mode' => 0o100644, 'size' => 0];
        }

        $running = $this->isUnder($path, self::RUNNING_FAILED_READ_SCHEME, self::RUNNING_EMPTY_READ_SCHEME);

        return $running ? ['mode' => 0o040755, 'size' => 0] : false;
    }

    public function stream_open(string $path): bool
    {
        $this->failsTheRead = $this->isUnder($path, self::GONE_FAILED_READ_SCHEME, self::RUNNING_FAILED_READ_SCHEME);

        return true;
    }

    public function stream_read(): false|string
    {
        $this->read = true;
        if ($this->failsTheRead) {
            trigger_error(self::READ_NOTICE, E_USER_NOTICE);

            return false;
        }

        return '';
    }

    /** Not at the end until a read has been tried, as a real file that has not been read is not. */
    public function stream_eof(): bool
    {
        return $this->read;
    }

    private function isUnder(string $path, string ...$schemes): bool
    {
        return array_any($schemes, static fn (string $scheme): bool => str_starts_with($path, $scheme . '://'));
    }
}
