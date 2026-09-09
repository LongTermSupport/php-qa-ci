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
        public string $branch,
        public bool $passes,
        public ?string $reason,
        public bool $isPlanBranch,
    ) {
    }

    public static function exempt(string $branch): self
    {
        return new self($branch, true, 'exempt', false);
    }

    public static function allowed(string $branch, string $prefix): self
    {
        return new self($branch, true, $prefix, false);
    }

    public static function disallowed(string $branch, bool $isPlanBranch): self
    {
        return new self($branch, false, null, $isPlanBranch);
    }
}
