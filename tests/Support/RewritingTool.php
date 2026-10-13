<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * A tool that rewrites a file under the source directory on each of its first
 * $rewrites runs, as a fixer that still has work to do would, and records how
 * often it ran.
 */
final class RewritingTool implements ToolInterface
{
    public int $runs = 0;

    public function __construct(
        private readonly string $name,
        private readonly int $rewrites,
        private readonly ?ToolResultDto $result = null,
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
        if ($this->runs <= $this->rewrites) {
            \Safe\file_put_contents($context->config->paths->srcDir . '/Rewritten.php', "<?php\n// run " . $this->runs . "\n");
        }

        return $this->result ?? ToolResultDto::passed();
    }
}
