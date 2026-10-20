<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;
use Safe\Exceptions\FilesystemException;
use UnexpectedValueException;

/**
 * A directory created empty for one job and removed with everything in it
 * afterwards: where the installers stage a download before moving the part
 * they keep into the library. Under the system temp directory, or beside the
 * file it will replace when that file must be swapped atomically.
 *
 * @internal
 */
final readonly class TemporaryDirectory
{
    /**
     * A staging directory beside a target unchanged for longer is a leftover; one newer may be
     * another run's, still downloading or unpacking into it.
     */
    private const int STALE_AFTER = 3600;

    /**
     * @param string                $path               the directory
     * @param array<string, string> $unremovedLeftovers each leftover besides() found and could not remove => why
     */
    private function __construct(
        public string $path,
        public array $unremovedLeftovers = [],
    ) {
    }

    public static function create(string $prefix): self
    {
        return new self(self::in(sys_get_temp_dir(), $prefix));
    }

    /**
     * A staging directory in the directory of the file it will replace (created if absent), so a
     * rename() from it is on one filesystem: the file is swapped atomically, and a process that
     * has the old one open or mapped keeps its inode. From the system temp directory on another
     * filesystem, rename() instead copies into the existing file and rewrites it in place.
     *
     * A staging directory with the same prefix that has not changed for STALE_AFTER seconds is one
     * a killed run left there, and is removed first. The prefix is the start of a file name: empty,
     * it would match every old directory beside the target; `.` or `..`, the directory itself or
     * its parent; with a slash, a name in another directory.
     *
     * Removing a leftover is best effort: one another run removed first is passed over, and one
     * that cannot be removed is kept and listed in unremovedLeftovers for the caller to warn about.
     * Only the new staging directory must be made.
     */
    public static function besides(string $target, string $prefix): self
    {
        if (\in_array($prefix, ['', '.', '..'], true) || false !== strpbrk($prefix, "/\0")) {
            throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a prefix that is the start of a file name, not "%s": its leftovers are found by it', $target, $prefix));
        }

        $parent = \dirname($target);
        if (!is_dir($parent)) {
            self::makeDirectory($parent, 0o755, true, $parent);
        }

        $unremoved = self::removeLeftovers($parent, $prefix);

        return new self(self::in($parent, $prefix), $unremoved);
    }

    /** Removes the directory and everything under it; a symlink is removed, never followed. */
    public function remove(): void
    {
        DirectoryTree::remove($this->path);
    }

    /**
     * Removes each directory (not a link, nor `.` or `..`) in $parent whose name begins with
     * $prefix and is older than STALE_AFTER. Each is first renamed to a fresh name with the prefix,
     * which only one of the runs that reach it can do, so that run alone removes it, and a
     * leftover gone by then was claimed by another. For the length of the cleanup a warning
     * becomes the exception, so a failure is reported once, by what is returned.
     *
     * @return array<string, string> each leftover kept => why it could not be removed
     */
    private static function removeLeftovers(string $parent, string $prefix): array
    {
        $candidates = [];
        foreach (new FilesystemIterator($parent, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME) as $path) {
            if (\is_string($path) && str_starts_with(basename($path), $prefix)) {
                $candidates[] = $path;
            }
        }

        $unremoved = [];
        set_error_handler(static function (int $level, string $message): never {
            throw new FilesystemException($message, 0, $level);
        });
        try {
            foreach ($candidates as $path) {
                $unremoved += self::removeLeftover($path, $parent . '/' . $prefix . bin2hex(random_bytes(8)));
            }
        } finally {
            restore_error_handler();
        }

        return $unremoved;
    }

    /**
     * Claims $path by renaming it to $claimed when it is a leftover, then removes it.
     *
     * @return array<string, string> the leftover kept => why, or nothing when it is gone or is not one
     */
    private static function removeLeftover(string $path, string $claimed): array
    {
        try {
            if (!is_dir($path) || is_link($path) || \Safe\filemtime($path) >= time() - self::STALE_AFTER) {
                return [];
            }

            \Safe\rename($path, $claimed);
        } catch (FilesystemException $filesystemException) {
            return file_exists($path) ? [$path => $filesystemException->getMessage()] : [];
        }

        try {
            DirectoryTree::remove($claimed);
        } catch (FilesystemException|UnexpectedValueException $exception) {
            return [$claimed => $exception->getMessage()];
        }

        return [];
    }

    /**
     * Made by mkdir() under the name it is given, so the directory is in $parent or not made at
     * all. tempnam() is not used: where it cannot create its file in the directory it is given, it
     * creates it in the system temp directory instead, with only a notice, which for besides()
     * would bring back the rename across filesystems it exists to avoid. The name ends in 16
     * random hex digits; mkdir() fails rather than reuse a directory already there.
     *
     * @return string the directory made
     */
    private static function in(string $parent, string $prefix): string
    {
        $path = $parent . '/' . $prefix . bin2hex(random_bytes(8));
        self::makeDirectory($path, 0o700, false, $parent);

        return $path;
    }

    /** For the length of the mkdir() its warning becomes the exception, which names the directory staged in. */
    private static function makeDirectory(string $path, int $mode, bool $recursive, string $parent): void
    {
        set_error_handler(static function (int $level, string $message) use ($parent): never {
            throw new RuntimeException(\sprintf('Cannot create a staging directory in %s: %s', $parent, $message), $level);
        });
        try {
            \Safe\mkdir($path, $mode, $recursive);
        } finally {
            restore_error_handler();
        }
    }
}
