<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes a directory tree, or what is in one. A link is removed, never
 * followed: a link to a directory reports isDir(), and rmdir() on it fails
 * with "Not a directory", while following it would empty its target.
 *
 * @internal
 */
final readonly class DirectoryTree
{
    private function __construct()
    {
    }

    /** Removes the directory and everything under it; a path that is not a directory is left alone. */
    public static function remove(string $path): void
    {
        if (!self::isDirectory($path)) {
            return;
        }

        self::empty($path);
        \Safe\rmdir($path);
    }

    /** Removes everything under the directory and keeps the directory; a path that is not a directory is left alone. */
    public static function empty(string $path): void
    {
        if (!self::isDirectory($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
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
    }

    private static function isDirectory(string $path): bool
    {
        return is_dir($path) && !is_link($path);
    }
}
