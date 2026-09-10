<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;

/**
 * What PipelineBuilder builds: the registry the CLI and the runner read, and
 * the implementation behind each canonical tool name.
 *
 * @internal
 */
final readonly class PipelineDefinitionDto
{
    /** @param array<string, ToolInterface> $tools keyed by canonical name */
    public function __construct(
        public ToolRegistry $registry,
        public array $tools,
    ) {
    }
}
