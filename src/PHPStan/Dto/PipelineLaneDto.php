<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

/**
 * One always-on php-qa-ci pipeline lane (e.g. branchNamePolicy), listed as an
 * active defence regardless of the project's PHPStan configuration.
 *
 * `docPath` is the route from the listing to the page stating the correct
 * construction (toolchain specification 5.1): naming a defence is only useful if
 * the reader can get from the name to the remediation. Null where the lane has no
 * page — a phase runner, which is not a defence, or a gap the listing then shows.
 *
 * @internal
 */
final readonly class PipelineLaneDto
{
    public function __construct(
        public string $name,
        public ?string $identifier,
        public string $summary,
        public ?string $phase,
        public ?string $optInVariable,
        public ?string $docPath = null,
    ) {
    }
}
