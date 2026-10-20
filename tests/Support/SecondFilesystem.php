<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A throwaway directory on a different filesystem from the system temp directory, for a test of a
 * replace that is only atomic when staged on the filesystem of the file it replaces. On Linux
 * /dev/shm is one. Where the candidate is not a directory, or is on the system temp directory's
 * filesystem (TMPDIR pointing into it), there is no second filesystem and the test is skipped,
 * saying why; the candidate is never made.
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
        $temp = sys_get_temp_dir();
        if (!is_dir($candidate)) {
            Assert::markTestSkipped(\sprintf('%s is not a directory here, so there is no second filesystem to stage across', $candidate));
        }

        if (self::device($candidate) === self::device($temp)) {
            Assert::markTestSkipped(\sprintf('%s and the system temp directory %s are on one filesystem, so a replace staged in the temp directory would not cross filesystems', $candidate, $temp));
        }

        return TempDir::createUnder($candidate, $prefix);
    }

    private static function device(string $path): int
    {
        $stat = stat($path);
        Assert::assertIsArray($stat, 'cannot stat ' . $path);

        return $stat['dev'];
    }
}
