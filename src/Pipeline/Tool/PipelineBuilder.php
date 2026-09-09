<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\PipelineDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownPhaseException;

/**
 * Immutable builder for the tool set a run executes. `defaults()` seeds it
 * with the shipped phases and tools; a project extends it without forking
 * the registry:
 *
 *   PipelineBuilder::defaults()
 *       ->withPhase(new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'))
 *       ->withTool(new ToolDefinitionDto('securityAudit', ['sa'], 'security audit', 'security', false), new SecurityAuditTool())
 *       ->build();
 *
 * A tool joins the end of its phase; a tool added under an existing name
 * replaces that one in place. Phases append unless inserted before a named
 * one, and `withPhaseOrder()` rewrites the whole order.
 *
 * @api
 */
final readonly class PipelineBuilder
{
    /**
     * @param list<PhaseDto>                   $phases      in execution order
     * @param array<string, ToolDefinitionDto> $definitions keyed by canonical name, in registry order, phase runners excluded
     * @param array<string, ToolInterface>     $tools       keyed by canonical name
     */
    private function __construct(
        private array $phases,
        private array $definitions,
        private array $tools,
    ) {
    }

    /** The shipped pipeline: the four phases and every shipped tool. */
    public static function defaults(): self
    {
        $shipped     = ToolRegistry::shipped();
        $definitions = [];
        foreach ($shipped->all() as $definition) {
            if (!$definition->isPhaseRunner) {
                $definitions[$definition->name] = $definition;
            }
        }

        return new self($shipped->phases(), $definitions, ShippedTools::all());
    }

    /** No phases and no tools: for a pipeline assembled entirely by the project. */
    public static function empty(): self
    {
        return new self([], [], []);
    }

    /** Append a phase, or insert it before the phase named by $before. */
    public function withPhase(PhaseDto $phase, ?string $before = null): self
    {
        if (null !== $this->phaseIndex($phase->name)) {
            throw new InvalidArgumentException(\sprintf("phase '%s' is already defined", $phase->name));
        }

        $index  = null === $before ? \count($this->phases) : $this->requirePhaseIndex($before);
        $phases = $this->phases;
        array_splice($phases, $index, 0, [$phase]);

        return new self($phases, $this->definitions, $this->tools);
    }

    /** The complete execution order: every defined phase, each named exactly once. */
    public function withPhaseOrder(string ...$names): self
    {
        $ordered = [];
        foreach ($names as $name) {
            $ordered[] = $this->phases[$this->requirePhaseIndex($name)];
        }

        $expected = array_map(static fn (PhaseDto $phase): string => $phase->name, $this->phases);
        $given    = array_values($names);
        sort($expected);
        sort($given);
        if ($expected !== $given) {
            throw new InvalidArgumentException(\sprintf('The phase order must name every phase exactly once; defined: %s', implode(', ', $expected)));
        }

        return new self($ordered, $this->definitions, $this->tools);
    }

    /**
     * Add a tool under the canonical name its definition and implementation
     * agree on. The definition's phase must exist, or be null for a tool the
     * phases do not run.
     */
    public function withTool(ToolDefinitionDto $definition, ToolInterface $tool): self
    {
        if ($definition->name !== $tool->name()) {
            throw new InvalidArgumentException(\sprintf("the tool defined as '%s' answers to '%s'", $definition->name, $tool->name()));
        }

        if (null !== $definition->phase) {
            $this->requirePhaseIndex($definition->phase);
        }

        return new self(
            $this->phases,
            [...$this->definitions, $definition->name => $definition],
            [...$this->tools, $tool->name() => $tool],
        );
    }

    public function build(): PipelineDefinitionDto
    {
        return new PipelineDefinitionDto(new ToolRegistry($this->phases, array_values($this->definitions)), $this->tools);
    }

    private function phaseIndex(string $name): ?int
    {
        foreach ($this->phases as $index => $phase) {
            if ($phase->name === $name) {
                return $index;
            }
        }

        return null;
    }

    private function requirePhaseIndex(string $name): int
    {
        return $this->phaseIndex($name) ?? throw UnknownPhaseException::forName(
            $name,
            ...array_map(static fn (PhaseDto $phase): string => $phase->name, $this->phases),
        );
    }
}
