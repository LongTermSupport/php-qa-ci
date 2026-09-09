<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\ToolGateEnum;

/**
 * One row of the tool registry: what `-t` tokens select it, where it runs,
 * and how it is described.
 *
 * `$phase` is the phase a leaf tool belongs to, the phase a phase runner runs,
 * and null for a pseudo-tool. `$isPhaseRunner` marks the four `all*` entries,
 * whose target is a phase rather than a leaf tool. `$target` names the
 * canonical tool a pseudo-tool actually runs (`uniterate` runs `phpunit`).
 * `$banner` is printed before the tool runs as part of a phase.
 *
 * @internal
 */
final readonly class ToolDefinitionDto
{
    /** @param list<string> $aliases the accepted -t tokens; the canonical name counts only if listed here */
    public function __construct(
        public string $name,
        public array $aliases,
        public string $description,
        public ?PhaseEnum $phase,
        public bool $supportsPaths,
        public ToolGateEnum $gate = ToolGateEnum::None,
        public ?string $banner = null,
        public bool $isPhaseRunner = false,
        public ?string $target = null,
        public bool $supportsJson = false,
    ) {
    }

    /**
     * Every token that selects this tool, the canonical name included.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return \in_array($this->name, $this->aliases, true) ? $this->aliases : [$this->name, ...$this->aliases];
    }

    /** The "a|b|c" display form of the accepted tokens. */
    public function display(): string
    {
        return implode('|', $this->aliases);
    }
}
