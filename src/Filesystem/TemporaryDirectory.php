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

    /** The random bytes in a staging directory's name, after its prefix, as twice as many hex digits. */
    private const int RANDOM_BYTES = 8;

    /** The characters tempnam() put after the prefix in an earlier version's staging directory name. */
    private const int TEMPNAM_CHARACTERS = 6;

    /** What each of those characters is drawn from. */
    private const string TEMPNAM_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

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
     * a killed run left there, and is removed first. Only a directory named exactly as this class
     * names one, the prefix and then 16 lowercase hex digits, or as an earlier version's tempnam()
     * named one, the prefix and then 6 letters and digits, is a staging directory. The prefix is
     * the start of a file name: empty, `.` or `..`, or with a slash, it is refused, as is one the
     * target's own name, or the name it resolves to through a link, begins with, which could make
     * the target a leftover. A target path must end in the target's own name: one ending in `/`,
     * `.` or `..`, or empty, is refused, since the directory it is in is not the one it names, and
     * so is a target that resolves through a link to the directory it is in or one above it.
     *
     * Removing a leftover is best effort: one another run removed first is passed over, one holding
     * what the target resolves to is in use and kept without a word, and one that cannot be removed
     * is kept and listed in unremovedLeftovers for the caller to warn about.
     * Only the new staging directory must be made.
     */
    public static function besides(string $target, string $prefix): self
    {
        if (\in_array($prefix, ['', '.', '..'], true) || false !== strpbrk($prefix, "/\0")) {
            throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a prefix that is the start of a file name, not "%s": its leftovers are found by it', $target, $prefix));
        }

        if (\in_array(basename($target), ['', '.', '..'], true) || str_ends_with($target, '/')) {
            throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a target path that ends in its own name, not in "/", "." or "..": its leftovers would be looked for in the wrong directory', $target));
        }

        if (str_starts_with(basename($target), $prefix)) {
            throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a prefix its name does not begin with, not "%s": the target would be one of its leftovers', $target, $prefix));
        }

        $resolved = null;
        if (file_exists($target)) {
            $resolved = \Safe\realpath($target);
            if (str_starts_with(basename($resolved), $prefix)) {
                throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a prefix the name it resolves to, %s, does not begin with, not "%s": the target would be one of its leftovers', $target, $resolved, $prefix));
            }

            if (self::contains($resolved, \Safe\realpath(\dirname($target)))) {
                throw new InvalidArgumentException(\sprintf('A staging directory beside %s needs a target that does not resolve to the directory it is in or one above it, not %s: the staging and its leftovers would be inside the target', $target, $resolved));
            }
        }

        $parent = \dirname($target);
        if (!is_dir($parent)) {
            self::makeDirectory($parent, 0o755, true, $parent);
        }

        $unremoved = self::removeLeftovers($parent, $prefix, $resolved);

        return new self(self::in($parent, $prefix), $unremoved);
    }

    /** Removes the directory and everything under it; a symlink is removed, never followed. */
    public function remove(): void
    {
        DirectoryTree::remove($this->path);
    }

    /**
     * Removes each directory (not a link) in $parent that is named as a staging directory with
     * $prefix and is older than STALE_AFTER and does not hold $resolvedTarget, what an existing
     * target resolves to. The target is never one: besides() refuses a prefix its name, or the
     * name it resolves to, begins with. Each is first renamed to a fresh name
     * with the prefix, which only one of the runs that reach it can do, so that run alone removes
     * it, and a leftover gone by then was claimed by another. For the length of the cleanup a
     * warning becomes the exception, so a failure is reported once, by what is returned.
     *
     * @return array<string, string> each leftover kept => why it could not be removed
     */
    private static function removeLeftovers(string $parent, string $prefix, ?string $resolvedTarget): array
    {
        $candidates = [];
        foreach (new FilesystemIterator($parent, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME) as $path) {
            if (\is_string($path) && self::isStagingName(basename($path), $prefix)) {
                $candidates[] = $path;
            }
        }

        $unremoved = [];
        set_error_handler(static function (int $level, string $message): never {
            throw new FilesystemException($message, severity: $level);
        });
        try {
            foreach ($candidates as $path) {
                $unremoved += self::removeLeftover($path, $parent . '/' . self::stagingName($prefix), $resolvedTarget);
            }
        } finally {
            restore_error_handler();
        }

        return $unremoved;
    }

    /**
     * Claims $path by renaming it to $claimed when it is a leftover, then removes it. One holding
     * what the target resolves to is in use, so is not a leftover.
     *
     * @return array<string, string> the leftover kept => why, or nothing when it is gone or is not one
     */
    private static function removeLeftover(string $path, string $claimed, ?string $resolvedTarget): array
    {
        try {
            if (!is_dir($path) || is_link($path) || \Safe\filemtime($path) >= time() - self::STALE_AFTER) {
                return [];
            }

            if (null !== $resolvedTarget && self::contains(\Safe\realpath($path), $resolvedTarget)) {
                return [];
            }

            \Safe\rename($path, $claimed);
        } catch (FilesystemException $filesystemException) {
            return file_exists($path) ? [$path => $filesystemException->getMessage()] : [];
        }

        try {
            DirectoryTree::remove($claimed);
        } catch (FilesystemException|UnexpectedValueException $exception) {
            return file_exists($claimed) ? [$claimed => $exception->getMessage()] : [];
        }

        return [];
    }

    /** Whether $path, a resolved path, is the directory $directory, also resolved, or is under it. */
    private static function contains(string $directory, string $path): bool
    {
        return str_starts_with(rtrim($path, '/') . '/', rtrim($directory, '/') . '/');
    }

    /** A fresh name for a staging directory: the prefix, then 16 random lowercase hex digits. */
    private static function stagingName(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    /**
     * Whether $name is one stagingName() makes with $prefix, the prefix then exactly 16 lowercase
     * hex digits, or one an earlier version's tempnam() made, the prefix then exactly 6 letters
     * and digits.
     */
    private static function isStagingName(string $name, string $prefix): bool
    {
        $length = \strlen($prefix);
        if (!str_starts_with($name, $prefix)) {
            return false;
        }

        return match (\strlen($name) - $length) {
            2 * self::RANDOM_BYTES   => 2 * self::RANDOM_BYTES   === strspn($name, '0123456789abcdef', $length),
            self::TEMPNAM_CHARACTERS => self::TEMPNAM_CHARACTERS === strspn($name, self::TEMPNAM_ALPHABET, $length),
            default                  => false,
        };
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
        $path = $parent . '/' . self::stagingName($prefix);
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
