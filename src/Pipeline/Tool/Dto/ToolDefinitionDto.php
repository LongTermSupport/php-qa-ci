<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool\Dto;

use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\ToolGateEnum;

/**
 * One row of the tool registry: what `-t` tokens select it, where it runs,
 * and how it is described.
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
        /** The phase a leaf tool belongs to; for a phase runner, the phase it runs; null for a pseudo-tool. */
        public ?PhaseEnum $phase,
        /** Whether -p applies to this tool. */
        public bool $supportsPaths,
        public ToolGateEnum $gate = ToolGateEnum::None,
        /** Banner printed before the tool runs in a phase. */
        public ?string $banner = null,
        /** True for allCS/allLints/allStatic/allTests: the target is the phase, not a leaf tool. */
        public bool $isPhaseRunner = false,
        /** The canonical tool actually run when this token is selected (pseudo-tools only). */
        public ?string $target = null,
        /** Whether --json is supported. */
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
