<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto;

/**
 * The project's additive branch-name overrides. `$file` is the file they were
 * read from, and is null when the project supplies none.
 *
 * @internal
 */
final readonly class BranchPolicyConfigDto
{
    /**
     * @param list<string> $extraAllowedPrefixes
     * @param list<string> $extraExemptBranches
     */
    public function __construct(
        public ?string $file,
        public array $extraAllowedPrefixes,
        public array $extraExemptBranches,
    ) {
    }
}
