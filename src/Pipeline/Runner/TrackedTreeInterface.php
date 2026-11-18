<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * What the project's git-tracked files look like right now, for the run to compare before and after
 * each step. Only the tracked files that differ from what git last recorded are listed, each with a
 * hash of its content, so a file that is dirty before the run and rewritten again by it is still seen.
 *
 * @internal
 */
interface TrackedTreeInterface
{
    /**
     * @return array<array-key, string>|null project-relative path => content hash, for each tracked
     *                                       file that differs from git's record of it (a path that is
     *                                       all digits is an int key); null when there is no answer
     *                                       (not a git work tree, or git failed)
     */
    public function dirtyFiles(ToolContext $context): ?array;
}
