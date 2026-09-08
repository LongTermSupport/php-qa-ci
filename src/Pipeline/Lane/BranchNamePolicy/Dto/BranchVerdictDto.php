<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto;

/**
 * @internal
 */
final readonly class BranchVerdictDto
{
    private function __construct(
        public string $branch,
        public bool $passes,
        /** Why it passed: "exempt" or the prefix matched; null when it failed. */
        public ?string $reason,
        /** A plan/* branch: extra-loud guidance applies. */
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
