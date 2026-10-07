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
 * it. Every floor here is one the installed extension enforces, which
 * TypeCoverageFloorsAreHonouredTest holds it to.
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
    ) {
    }

    public function enabled(): bool
    {
        return null !== $this->returnType
            || null !== $this->paramType
            || null !== $this->propertyType
            || null !== $this->constantType;
    }

    /**
     * The tool's own neon keys, only for the floors actually set.
     *
     * @return array<string, int>
     */
    public function neonParameters(): array
    {
        return array_filter([
            'return_type'   => $this->returnType,
            'param_type'    => $this->paramType,
            'property_type' => $this->propertyType,
            'constant_type' => $this->constantType,
        ], static fn (?int $floor): bool => null !== $floor);
    }
}
