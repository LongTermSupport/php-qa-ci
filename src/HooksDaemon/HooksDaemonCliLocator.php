<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use Closure;
use Safe\Exceptions\FilesystemException;

/**
 * Finds the hooks daemon's CLI for a project: in the project itself, or in a
 * directory above it up to the root of the git work tree the project sits in,
 * which is where a monorepo keeps one daemon for every package. The walk stops
 * at that root, because a daemon above it belongs to another checkout.
 *
 * A linked worktree is a checkout of the same repository, and the daemon,
 * which the repository ignores, is installed only in the main one; so at a
 * linked worktree's root the walk continues in the main checkout, from the
 * project's place in it up to its root. A submodule's root does not: the
 * repository holding it is another project.
 *
 * @internal
 */
final readonly class HooksDaemonCliLocator
{
    /** The CLI wrapper the daemon's installer deploys, relative to the directory holding `.claude/`. */
    public const string CLI = '.claude/hooks-daemon/bin/hooks-daemon';

    /** A work tree's `.git`: a directory in a clone, a file in a linked worktree or submodule. */
    private const string GIT = '.git';

    /** How a `.git` file names the git directory it stands for. */
    private const string GITDIR_PREFIX = 'gitdir:';

    /** In a linked worktree's git directory only: the path to the repository's own `.git`. */
    private const string COMMONDIR = 'commondir';

    /**
     * @param Closure(string): string $read reads a file whole, and throws when it cannot: a
     *                                      pointer that exists but cannot be read leaves the
     *                                      daemon's presence unknown, which is not its absence
     */
    public function __construct(private Closure $read = \Safe\file_get_contents(...))
    {
    }

    /**
     * @throws FilesystemException when a worktree's pointer to its main checkout cannot be read
     */
    public function locate(string $projectRoot): ?string
    {
        $directory = $projectRoot;
        // The directories climbed out of, so the walk in a main checkout retraces them exactly.
        $place = '';
        while (true) {
            $cli = $directory . '/' . self::CLI;
            if (is_file($cli)) {
                return $cli;
            }

            $parent = \dirname($directory);
            if (file_exists($directory . '/' . self::GIT)) {
                $main = $this->mainCheckout($directory . '/' . self::GIT);

                return null === $main ? null : $this->inMainCheckout($main, $place);
            }

            if ($parent === $directory) {
                return null;
            }

            $place     = '/' . basename($directory) . $place;
            $directory = $parent;
        }
    }

    /**
     * The walk again in the main checkout, from the directory at the project's
     * place in it up to its root, so a package's own daemon serves that package.
     */
    private function inMainCheckout(string $main, string $place): ?string
    {
        $directory = $main . $place;
        while (true) {
            $cli = $directory . '/' . self::CLI;
            if (is_file($cli)) {
                return $cli;
            }

            if ($directory === $main) {
                return null;
            }

            $directory = \dirname($directory);
        }
    }

    /**
     * The main checkout's root when `.git` is a linked worktree's pointer file;
     * null for a clone's own `.git` directory, a submodule, or a bare repository.
     */
    private function mainCheckout(string $git): ?string
    {
        if (!is_file($git)) {
            return null;
        }

        $pointer = trim(($this->read)($git));
        if (!str_starts_with($pointer, self::GITDIR_PREFIX)) {
            return null;
        }

        $gitDir    = $this->resolve(\dirname($git), trim(substr($pointer, \strlen(self::GITDIR_PREFIX))));
        $commonDir = $gitDir . '/' . self::COMMONDIR;
        if (!is_file($commonDir)) {
            return null;
        }

        $repository = $this->resolve($gitDir, trim(($this->read)($commonDir)));
        if (!is_dir($repository)) {
            return null;
        }

        $repository = \Safe\realpath($repository);

        return self::GIT === basename($repository) ? \dirname($repository) : null;
    }

    /** A path git wrote relative to the directory holding it, or absolute. */
    private function resolve(string $base, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $base . '/' . $path;
    }
}
