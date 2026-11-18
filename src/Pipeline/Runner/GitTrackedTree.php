<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The tracked files git reports as differing from the last commit, each with a hash of what is on
 * disk now. `git diff HEAD` compares the work tree and the index with HEAD from git's own stat cache,
 * so a check after every lane costs one comparison rather than a read of the whole tree, and only the
 * few files that differ are read.
 *
 * `--relative` names each file from the project root and leaves out anything outside it, so a project
 * that is a subdirectory of a larger repository is judged on its own files and a sibling project's
 * changes are not blamed on this run.
 *
 * @internal
 */
final readonly class GitTrackedTree implements TrackedTreeInterface
{
    /** Joins the project root to a project-relative path. */
    private const string SEPARATOR = '/';

    public function dirtyFiles(ToolContext $context): ?array
    {
        $root   = $context->config->paths->projectRoot;
        $result = $context->processes->run(new ProcessSpecDto(
            ['git', 'diff', '--relative', '--name-only', '-z', '--no-renames', 'HEAD'],
            $root,
            ['GIT_OPTIONAL_LOCKS' => '0'],
            streamOutput: false,
        ));

        if (!$result->succeeded()) {
            return null;
        }

        /** @var array<array-key, string> $dirty a path that is all digits is an int key */
        $dirty = [];
        foreach (explode("\0", $result->output) as $path) {
            if ('' === $path) {
                continue;
            }

            $dirty[$path] = $this->contentHash($root . self::SEPARATOR . $path);
        }

        return $dirty;
    }

    private function contentHash(string $file): string
    {
        return is_file($file) ? \Safe\hash_file('sha256', $file) : 'absent';
    }
}
