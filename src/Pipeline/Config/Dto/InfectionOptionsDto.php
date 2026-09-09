<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * What the Infection lane runs with.
 *
 * `$enabled` is forced off when coverage is off, since mutation testing cannot
 * run without it. The MSI floors are percentages. `$diffBase` is a git ref;
 * when set, mutation is scoped to the files changed against it and
 * `$diffCoveredMsi` is the floor that applies instead of the two whole-codebase
 * ones.
 *
 * @api
 */
final readonly class InfectionOptionsDto
{
    public function __construct(
        public bool $enabled,
        public int $threads,
        public bool $onlyCovered,
        public int $minMsi,
        public int $minCoveredMsi,
        public ?string $diffBase,
        public int $diffCoveredMsi,
    ) {
    }
}
