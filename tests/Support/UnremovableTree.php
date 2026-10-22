<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A directory nested deeper than PATH_MAX, so nothing that walks it by absolute path can remove
 * it: opening its deep directories fails with "File name too long". Unlike a mode bit or an
 * immutable flag, this holds when the tests run as root and needs no capability. It is built
 * and taken down one relative step at a time from inside the tree, which no length limit stops.
 */
final readonly class UnremovableTree
{
    /** Levels below the planted path: 25 of NAME_LENGTH is about 5,000, past Linux's PATH_MAX of 4,096. */
    private const int DEPTH = 25;

    /** Characters in each level's name, under NAME_MAX (255) so each one alone can be created. */
    private const int NAME_LENGTH = 200;

    private function __construct()
    {
    }

    public static function plant(string $path): void
    {
        \Safe\mkdir($path, 0o777, true);
        $cwd = \Safe\getcwd();

        try {
            \Safe\chdir($path);
            for ($level = 0; $level < self::DEPTH; ++$level) {
                \Safe\mkdir(self::name());
                \Safe\chdir(self::name());
            }
        } finally {
            \Safe\chdir($cwd);
        }
    }

    /** Removes what plant() built; a path that is not a directory is left alone. */
    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $cwd = \Safe\getcwd();

        try {
            \Safe\chdir($path);
            $depth = 0;
            while (is_dir(self::name())) {
                \Safe\chdir(self::name());
                ++$depth;
            }

            for (; $depth > 0; --$depth) {
                \Safe\chdir('..');
                \Safe\rmdir(self::name());
            }
        } finally {
            \Safe\chdir($cwd);
        }

        \Safe\rmdir($path);
    }

    private static function name(): string
    {
        return str_repeat('d', self::NAME_LENGTH);
    }
}
