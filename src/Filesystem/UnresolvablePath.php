<?php

declare(strict_types=1);

namespace LTS\PHPQA\Filesystem;

use SplFileInfo;

/**
 * A file a lane walks to whose real path cannot be resolved: a symlink whose target
 * does not exist, ordinary in a consumer checkout. A tool given one crashes, or fails
 * without naming it, so a lane skips it and names it in one shared line instead.
 *
 * Such a file has no real path to match an ignored path against, so it is named, and
 * matched, by its directory's real path and its own name: the nominal path.
 *
 * A lane whose tool walks the files in a child process (PHP CS Fixer, PHPArkitect) has
 * the walk append each skipped file to a list file, which the lane reads back once the
 * tool has run. A walk may run more than once, so the list may hold a name more than once.
 *
 * @internal
 */
final readonly class UnresolvablePath
{
    private const string REASON = 'its real path cannot be resolved (a symlink whose target does not exist), so it was not checked';

    private function __construct()
    {
    }

    /** A link with no real path; a path that is not there at all is not one. */
    public static function is(string $path): bool
    {
        return is_link($path) && false === self::realPath($path);
    }

    /** The real path of the directory holding it and its own name, or the path as given when the directory has none. */
    public static function nominal(string $path): string
    {
        $directory = self::realPath(\dirname($path));

        return false === $directory ? $path : $directory . '/' . basename($path);
    }

    /** The line a lane prints for a file it skipped, the path named relative to the project root when under it. */
    public static function skipped(string $tool, string $path, string $projectRoot): string
    {
        return \sprintf('%s skipped %s: %s', $tool, self::relative($path, $projectRoot), self::REASON);
    }

    /**
     * The entries of a list file a walk appended to, each once, in the order first written.
     *
     * @return list<string>
     */
    public static function listedIn(string $list): array
    {
        if (!is_file($list)) {
            return [];
        }

        $entries = array_filter(explode("\n", \Safe\file_get_contents($list)), static fn (string $line): bool => '' !== $line);

        return array_values(array_unique($entries));
    }

    /** Removes a list file a previous run left, which the next run would otherwise report again. */
    public static function forget(string $list): void
    {
        if (is_file($list)) {
            \Safe\unlink($list);
        }
    }

    private static function relative(string $path, string $projectRoot): string
    {
        $path  = self::tidy($path);
        $roots = [rtrim(self::tidy($projectRoot), '/') . '/'];
        $real  = self::realPath($projectRoot);
        if (false !== $real) {
            $roots[] = $real . '/';
        }

        foreach ($roots as $root) {
            if (str_starts_with($path, $root)) {
                return substr($path, \strlen($root));
            }
        }

        return $path;
    }

    /** The path for display, without doubled slashes or `.` segments; `..` is kept, as resolving it needs the filesystem. */
    private static function tidy(string $path): string
    {
        do {
            $before = $path;
            $path   = str_replace(['//', '/./'], '/', $path);
        } while ($before !== $path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    /**
     * The real path, or false where there is none. Asked of SplFileInfo because "none" is
     * the answer this class exists for, and the Safe library's realpath() throws instead.
     */
    private static function realPath(string $path): false|string
    {
        return new SplFileInfo($path)->getRealPath();
    }
}
