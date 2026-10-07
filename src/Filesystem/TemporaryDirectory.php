<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A directory under the system temp directory, created empty for one job and
 * removed with everything in it afterwards: where the installers stage a
 * download before moving the part they keep into the library.
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
        $path = \Safe\tempnam(sys_get_temp_dir(), $prefix);
        \Safe\unlink($path);
        \Safe\mkdir($path, 0o700, true);

        return new self($path);
    }

    /** Removes the directory and everything under it; a symlink is removed, never followed. */
    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                \Safe\rmdir($entry->getPathname());
            } else {
                \Safe\unlink($entry->getPathname());
            }
        }

        \Safe\rmdir($this->path);
    }
}
