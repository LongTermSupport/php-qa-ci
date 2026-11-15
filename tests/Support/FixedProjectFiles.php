<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\ProjectFilesInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * Answers a lane's question "which files does the project have" with a list the test chose, so a
 * lane that reads the project's files is tested without a git work tree.
 */
final class FixedProjectFiles implements ProjectFilesInterface
{
    public int $asked = 0;

    /** @param list<string>|null $files project-relative, as git prints them; null when git cannot answer */
    public function __construct(private readonly ?array $files)
    {
    }

    public function files(ToolContext $context): ?array
    {
        ++$this->asked;

        return $this->files;
    }
}
