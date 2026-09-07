<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

/**
 * One always-on php-qa-ci pipeline lane (e.g. branchNamePolicy), listed as an
 * active defence regardless of the project's PHPStan configuration.
 *
 * @internal
 */
final readonly class PipelineLaneDto
{
    public function __construct(
        public string $name,
        public ?string $identifier,
        public string $summary,
    ) {
    }
}
