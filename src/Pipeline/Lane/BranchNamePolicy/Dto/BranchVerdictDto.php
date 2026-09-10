<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto;

/**
 * The branch-name policy's decision about one branch.
 *
 * `$reason` says why it passed, either the word "exempt" or the allowed prefix
 * it matched, and is null when it failed. `$isPlanBranch` marks a plan branch,
 * which gets the extra-loud guidance rather than the ordinary failure.
 *
 * @internal
 */
final readonly class BranchVerdictDto
{
    private function __construct(
        public bool $passes,
        public ?string $reason,
        public bool $isPlanBranch,
    ) {
    }

    public static function exempt(): self
    {
        return new self(true, 'exempt', false);
    }

    public static function allowed(string $prefix): self
    {
        return new self(true, $prefix, false);
    }

    public static function disallowed(bool $isPlanBranch): self
    {
        return new self(false, null, $isPlanBranch);
    }
}
