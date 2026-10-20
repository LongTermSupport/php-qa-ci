<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use RuntimeException;

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

    /**
     * Made by mkdir() under the name it is given, so the directory is in $parent or not made at
     * all. tempnam() is not used: where it cannot create its file in the directory it is given, it
     * creates it in the system temp directory instead, with only a notice, which for besides()
     * would bring back the rename across filesystems it exists to avoid. For the length of the
     * mkdir() its warning becomes the exception, which names the directory.
     */
    private static function in(string $parent, string $prefix): self
    {
        $path = $parent . '/' . $prefix . bin2hex(random_bytes(8));
        set_error_handler(static function (int $level, string $message) use ($parent): never {
            throw new RuntimeException(\sprintf('Cannot create a staging directory in %s: %s', $parent, $message), $level);
        });
        try {
            mkdir($path, 0o700);
        } finally {
            restore_error_handler();
        }

        return new self($path);
    }
}
