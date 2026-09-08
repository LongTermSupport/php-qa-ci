<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * @internal
 */
final readonly class PhpUnitOptionsDto
{
    public function __construct(
        /** Run with Xdebug coverage (needed by Infection). Forced off without Xdebug. */
        public bool $coverage,
        /** Exported as phpUnitQuickTests so tests can take a faster path. */
        public bool $quickTests,
        /** Order by defects, stop on first failure, no coverage. */
        public bool $iterativeMode,
    ) {
    }
}
