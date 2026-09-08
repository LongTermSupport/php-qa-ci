<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * @internal
 */
final readonly class InfectionOptionsDto
{
    public function __construct(
        /** Forced off when coverage is off. */
        public bool $enabled,
        public int $threads,
        public bool $onlyCovered,
        /** Minimum mutation score indicator, per cent. */
        public int $minMsi,
        /** Minimum covered-code MSI, per cent. */
        public int $minCoveredMsi,
        /** A git ref; when set, mutation is scoped to files changed against it. */
        public ?string $diffBase,
        /** Covered-MSI floor applied in diff mode. */
        public int $diffCoveredMsi,
    ) {
    }
}
