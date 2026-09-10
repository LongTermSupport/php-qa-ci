<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto\BranchVerdictDto;

/**
 * Pure decision: a branch passes when it is exempt (the detected default
 * branch or a configured exemption) or starts with an allowed prefix.
 *
 * @internal
 */
final readonly class BranchNamePolicyDecision
{
    /** @var list<string> */
    public const array DEFAULT_PREFIXES = ['feature/', 'bugfix/', 'chore/', 'hotfix/'];

    /** @param list<string> $exemptBranches */
    public function decide(string $branch, array $exemptBranches, string ...$allowedPrefixes): BranchVerdictDto
    {
        if (\in_array($branch, $exemptBranches, true)) {
            return BranchVerdictDto::exempt();
        }

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($branch, $prefix)) {
                return BranchVerdictDto::allowed($prefix);
            }
        }

        return BranchVerdictDto::disallowed(str_starts_with($branch, 'plan/'));
    }
}
