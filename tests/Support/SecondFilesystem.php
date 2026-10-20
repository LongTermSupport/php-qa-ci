<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A throwaway directory on a different filesystem from the system temp directory, for a test of a
 * replace that is only atomic when staged on the filesystem of the file it replaces. On Linux
 * /dev/shm is one.
 */
final readonly class SecondFilesystem
{
    /** A tmpfs on Linux, apart from the system temp directory unless TMPDIR points into it. */
    public const string SHM = '/dev/shm';

    private function __construct()
    {
    }

    public static function directory(string $prefix, string $candidate = self::SHM): TempDir
    {
        return TempDir::createUnder($candidate, $prefix);
    }
}
