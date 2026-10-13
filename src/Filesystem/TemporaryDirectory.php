<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

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
    private function __construct(public string $path)
    {
    }

    public static function create(string $prefix): self
    {
        return self::in(sys_get_temp_dir(), $prefix);
    }

    /**
     * A staging directory in the directory of the file it will replace (created if absent), so a
     * rename() from it is on one filesystem: the file is swapped atomically, and a process that
     * has the old one open or mapped keeps its inode. From the system temp directory on another
     * filesystem, rename() instead copies into the existing file and rewrites it in place.
     */
    public static function besides(string $target, string $prefix): self
    {
        $parent = \dirname($target);
        if (!is_dir($parent)) {
            \Safe\mkdir($parent, 0o755, true);
        }

        return self::in($parent, $prefix);
    }

    /** Removes the directory and everything under it; a symlink is removed, never followed. */
    public function remove(): void
    {
        DirectoryTree::remove($this->path);
    }

    private static function in(string $parent, string $prefix): self
    {
        $path = \Safe\tempnam($parent, $prefix);
        \Safe\unlink($path);
        \Safe\mkdir($path, 0o700, true);

        return new self($path);
    }
}
