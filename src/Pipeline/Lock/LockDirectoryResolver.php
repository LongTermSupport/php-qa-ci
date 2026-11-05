<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * Where the run lock lives. Every git worktree of a repository shares one
 * common git directory, so a lock kept there is one lock for the repository:
 * runs started from different worktrees exclude each other. It is inside the
 * git directory, so it can never be tracked. A project that is not the root
 * of a git work tree keeps the lock under its own qaConfig/.qa-lock.
 *
 * @internal
 */
final readonly class LockDirectoryResolver
{
    private const string GIT_SUBDIRECTORY = 'php-qa-ci-lock';

    private const string FALLBACK = '/qaConfig/.qa-lock';

    /** @var list<string> the environment variables that make git work on a repository other than the one at the cwd */
    private const array REPOSITORY_VARIABLES = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_COMMON_DIR', 'GIT_INDEX_FILE', 'GIT_PREFIX'];

    public function __construct(private ProcessRunnerInterface $processes)
    {
    }

    public function resolve(string $projectRoot): string
    {
        $common = $this->commonGitDirectory($projectRoot);

        return null === $common ? $projectRoot . self::FALLBACK : $common . '/' . self::GIT_SUBDIRECTORY;
    }

    /** The shared git directory, or null when the project is not the top of a work tree. */
    private function commonGitDirectory(string $projectRoot): ?string
    {
        // `env -u` removes the variables that pick a repository for git, so a
        // caller that exports them (a git hook, say) cannot make an unrelated
        // project share this repository's lock: the project root alone decides.
        $unset = [];
        foreach (self::REPOSITORY_VARIABLES as $variable) {
            $unset[] = '-u';
            $unset[] = $variable;
        }

        $result = $this->processes->run(new ProcessSpecDto(
            ['env', ...$unset, 'git', 'rev-parse', '--path-format=absolute', '--show-toplevel', '--git-common-dir'],
            $projectRoot,
            streamOutput: false,
        ));
        if (!$result->succeeded()) {
            return null;
        }

        $lines = explode("\n", trim($result->stdout));
        if (2 !== \count($lines)) {
            return null;
        }

        [$topLevel, $common] = $lines;
        if (!str_starts_with($common, '/') || $topLevel !== \Safe\realpath($projectRoot)) {
            return null;
        }

        return rtrim($common, '/');
    }
}
