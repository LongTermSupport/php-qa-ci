<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * A tool that answers from a queue of results and records how often it ran.
 */
final class StubTool implements ToolInterface
{
    public int $runs = 0;

    /** @var list<ToolResultDto> */
    private array $queue;

    public function __construct(
        private readonly string $name,
        ToolResultDto ...$results,
    ) {
        $this->queue = array_values($results);
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
        $next = array_shift($this->queue);

        return $next ?? ToolResultDto::passed();
    }
}
