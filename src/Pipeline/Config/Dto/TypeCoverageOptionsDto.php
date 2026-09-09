<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * Minimum percentage of declarations that must carry a native type, per kind,
 * enforced by tomasvotruba/type-coverage through the phpstan lane.
 *
 * Every floor is null by default, meaning that kind is not measured at all.
 * The tool's own defaults are 99, which is not a floor a project can adopt on
 * the day it installs the pipeline; a project raises each ratchet as it earns
 * it. `$declare` is the share of files carrying `declare(strict_types=1)` —
 * separate from the phpStrictTypes lane, which requires it everywhere, so a
 * project running that lane is already at 100 here.
 *
 * @api
 */
final readonly class TypeCoverageOptionsDto
{
    public function __construct(
        public ?int $returnType = null,
        public ?int $paramType = null,
        public ?int $propertyType = null,
        public ?int $constantType = null,
        public ?int $declare = null,
    ) {
    }

    public function enabled(): bool
    {
        return null !== $this->returnType
            || null !== $this->paramType
            || null !== $this->propertyType
            || null !== $this->constantType
            || null !== $this->declare;
    }

    /**
     * The tool's own neon keys, only for the floors actually set.
     *
     * @return array<string, int>
     */
    public function neonParameters(): array
    {
        return array_filter([
            'return_type' => $this->returnType,
            'param_type' => $this->paramType,
            'property_type' => $this->propertyType,
            'constant_type' => $this->constantType,
            'declare' => $this->declare,
        ], static fn (?int $floor): bool => null !== $floor);
    }
}
