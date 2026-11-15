<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The project's files as git lists them: everything tracked, plus new files not yet committed,
 * minus what `.gitignore` excludes, so a git-ignored checkout such as `.claude/worktrees/` is
 * not part of the project. Only a file still on disk is listed: git names what the index holds,
 * and on a dirty tree that can include a file that has been deleted.
 *
 * @internal
 */
final readonly class GitProjectFiles implements ProjectFilesInterface
{
    private const string SEPARATOR = '/';

    public function files(ToolContext $context): ?array
    {
        $root   = $context->config->paths->projectRoot;
        $result = $context->processes->run(new ProcessSpecDto(
            ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            $root,
            streamOutput: false,
        ));

        if (!$result->succeeded()) {
            return null;
        }

        return array_values(array_filter(
            explode("\0", $result->output),
            static fn (string $file): bool => '' !== $file && is_file($root . self::SEPARATOR . $file),
        ));
    }
}
