<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes a directory tree, or what is in one. A link found inside the tree
 * is removed, never followed: a link to a directory reports isDir(), and
 * rmdir() on it fails with "Not a directory", while following it would empty
 * its target. The path the caller names may itself be a link to a directory,
 * as when a project points an output directory at another disk: remove()
 * removes that link, and empty() empties what it points at.
 *
 * @internal
 */
final readonly class DirectoryTree
{
    private function __construct()
    {
    }

    /** Removes the directory and everything under it, or the link to it; a path that is not a directory is left alone. */
    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        if (is_link($path)) {
            \Safe\unlink($path);

            return;
        }

        self::empty($path);
        \Safe\rmdir($path);
    }

    /** Removes everything under the directory, or under the one a link points at, and keeps it; a path that is not a directory is left alone. */
    public static function empty(string $path): void
    {
        if (!is_dir($path)) {
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
}
