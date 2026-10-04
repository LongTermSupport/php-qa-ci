<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * Finds the hooks daemon's CLI for a project: in the project itself, or in a
 * directory above it up to the root of the git work tree the project sits in,
 * which is where a monorepo keeps one daemon for every package. The walk stops
 * at that root, because a daemon above it belongs to another checkout.
 *
 * @internal
 */
final readonly class HooksDaemonCliLocator
{
    /** The CLI wrapper the daemon's installer deploys, relative to the directory holding `.claude/`. */
    public const string CLI = '.claude/hooks-daemon/bin/hooks-daemon';

    /** A work tree's `.git`: a directory in a clone, a file in a linked worktree or submodule. */
    private const string GIT = '.git';

    public function locate(string $projectRoot): ?string
    {
        $directory = $projectRoot;
        while (true) {
            $cli = $directory . '/' . self::CLI;
            if (is_file($cli)) {
                return $cli;
            }

            $parent = \dirname($directory);
            if (file_exists($directory . '/' . self::GIT) || $parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}
