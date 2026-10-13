<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Copies what git sees in a checkout: the tracked files, and the untracked
 * ones that are not ignored, so local edits are included and what the
 * checkout ignores (untracked/, vendor/, var/) is not. A link is copied as a
 * link. A tracked file deleted from the working tree is skipped.
 */
final readonly class GitFilesCopy
{
    private function __construct()
    {
    }

    public static function copy(string $from, string $to): void
    {
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $from, GitSandbox::environment());
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('git ls-files failed in %s: %s', $from, $process->getErrorOutput()));
        }

        foreach (array_unique(array_filter(explode("\0", $process->getOutput()))) as $path) {
            self::copyOne($from . '/' . $path, $to . '/' . $path);
        }
    }

    private static function copyOne(string $source, string $target): void
    {
        if (!is_link($source) && !is_file($source)) {
            return;
        }

        if (!is_dir(\dirname($target))) {
            \Safe\mkdir(\dirname($target), 0o755, true);
        }

        if (is_link($source)) {
            \Safe\symlink(\Safe\readlink($source), $target);

            return;
        }

        \Safe\copy($source, $target);
        \Safe\chmod($target, \Safe\fileperms($source) & 0o777);
    }
}
