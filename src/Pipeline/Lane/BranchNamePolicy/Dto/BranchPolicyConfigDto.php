<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto;

/**
 * @internal
 */
final readonly class BranchPolicyConfigDto
{
    /**
     * @param list<string> $extraAllowedPrefixes
     * @param list<string> $extraExemptBranches
     */
    public function __construct(
        /** The file the overrides were read from, or null when there is none. */
        public ?string $file,
        public array $extraAllowedPrefixes,
        public array $extraExemptBranches,
    ) {
    }
}
