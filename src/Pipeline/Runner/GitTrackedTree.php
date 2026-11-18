<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The tracked files git reports as differing from its record, from `git status`, each with a hash of
 * what is on disk now. git does the comparing from its own stat cache, so a check after every lane
 * costs one status rather than a read of the whole tree, and only the few files that differ are read.
 *
 * Each value also carries the two status letters, so a file that is staged, then rewritten, is a
 * different state from one that was only staged.
 *
 * @internal
 */
final readonly class GitTrackedTree implements TrackedTreeInterface
{
    private const string SEPARATOR = '/';

    /** The status letters take three characters of a record: two letters and a space. */
    private const int PATH_OFFSET = 3;

    public function dirtyFiles(ToolContext $context): ?array
    {
        $root   = $context->config->paths->projectRoot;
        $result = $context->processes->run(new ProcessSpecDto(
            ['git', 'status', '--porcelain=v1', '-z', '--untracked-files=no', '--no-renames'],
            $root,
            ['GIT_OPTIONAL_LOCKS' => '0'],
            streamOutput: false,
        ));

        if (!$result->succeeded()) {
            return null;
        }

        $dirty = [];
        foreach (explode("\0", $result->output) as $record) {
            if (\strlen($record) <= self::PATH_OFFSET) {
                continue;
            }

            $path         = substr($record, self::PATH_OFFSET);
            $dirty[$path] = substr($record, 0, 2) . ':' . $this->contentHash($root . self::SEPARATOR . $path);
        }

        return $dirty;
    }

    private function contentHash(string $file): string
    {
        return is_file($file) ? \Safe\hash_file('sha256', $file) : 'absent';
    }
}
