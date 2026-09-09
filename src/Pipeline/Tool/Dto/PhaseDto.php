<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

/**
 * One phase of the pipeline: the name a tool joins it by, the banner printed
 * when it starts, and the `-t` runner that selects the whole phase.
 *
 * The shipped four come from PhaseEnum; a project adds its own through
 * PipelineBuilder::withPhase(). The runner is derived here rather than listed
 * separately so a phase cannot exist without one, or point at a phase that
 * does not.
 *
 * @api
 */
final readonly class PhaseDto
{
    /** @param list<string> $runnerAliases the `-t` tokens that run the whole phase, e.g. ['allLints'] */
    public function __construct(
        public string $name,
        public string $banner,
        public string $runnerName,
        public array $runnerAliases,
        public string $runnerDescription,
    ) {
    }

    public function runner(): ToolDefinitionDto
    {
        return new ToolDefinitionDto($this->runnerName, $this->runnerAliases, $this->runnerDescription, $this->name, false, isPhaseRunner: true);
    }
}
