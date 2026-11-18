<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * A lane that passes and, as it does, writes a tracked file: the lane a read-only run must catch.
 */
final class TreeTouchingTool implements ToolInterface
{
    public int $runs = 0;

    public function __construct(
        private readonly string $name,
        private readonly ScriptedTrackedTree $tree,
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function identifier(): string
    {
        return 'phpqaci.' . $this->name;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        ++$this->runs;
        $context->writeln('[' . $this->name . ' ran]');
        $this->tree->touch($this->path, 'run ' . $this->runs);

        return ToolResultDto::passed();
    }
}
