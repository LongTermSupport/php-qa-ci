<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

/**
 * One phase of the pipeline: the name a tool joins it by, the banner printed
 * when it starts, and the `-t` runner that selects the whole phase.
 *
 * A phase whose tools rewrite the checked paths sets $repeatsUntilSettled: a
 * writable run then repeats the phase until a pass changes nothing, so a tool
 * sees what the tools after it wrote (RepeatUntilSettled).
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
        public bool $repeatsUntilSettled = false,
    ) {
    }

    public function runner(): ToolDefinitionDto
    {
        return new ToolDefinitionDto($this->runnerName, $this->runnerAliases, $this->runnerDescription, $this->name, false, isPhaseRunner: true);
    }
}
