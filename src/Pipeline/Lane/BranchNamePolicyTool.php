<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyConfig;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * A PR branch must use an allowed prefix (feature/, bugfix/, chore/,
 * hotfix/, plus any the project adds), never plan/*. The detected default
 * branch and any configured exemptions pass. Outside a git work tree, or on
 * a detached HEAD, there is nothing to judge.
 *
 * @internal
 */
final readonly class BranchNamePolicyTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.branchNamePolicy';

    public function __construct(
        private BranchNamePolicyDecision $decision = new BranchNamePolicyDecision(),
        private BranchNamePolicyConfig $config = new BranchNamePolicyConfig(),
    ) {
    }

    public function name(): string
    {
        return 'branchNamePolicy';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        $git  = new GitBranches($context->processes, $root);

        if (!$git->isWorkTree()) {
            $context->writeln(\sprintf('[branchNamePolicy] No git repository detected at %s — skipping check.', $root));

            return ToolResultDto::skipped('not a git work tree');
        }

        $branch = $git->currentBranch();
        if (null === $branch) {
            $context->writeln('[branchNamePolicy] Detached HEAD or no branch detected — skipping check.');

            return ToolResultDto::skipped('detached HEAD');
        }

        $context->writeln('[branchNamePolicy] Current branch: ' . $branch);

        $exempt        = [];
        $defaultBranch = $git->defaultBranch();
        if (null !== $defaultBranch) {
            $context->writeln('[branchNamePolicy] Default branch detected: ' . $defaultBranch);
            $exempt[] = $defaultBranch;
        } else {
            $context->writeln('[branchNamePolicy] WARNING: could not detect default branch via git symbolic-ref or git ls-remote. If this branch should be exempt, add it to qaConfig/branchNamePolicy.yaml under extra_exempt_branches.');
        }

        $overrides = $this->config->load($context->config->paths->projectConfigDir);
        if (null !== $overrides->file) {
            $context->writeln('[branchNamePolicy] Loading project overrides from ' . $overrides->file);
        }

        $prefixes = [...BranchNamePolicyDecision::DEFAULT_PREFIXES, ...$overrides->extraAllowedPrefixes];
        $exempt   = [...$exempt, ...$overrides->extraExemptBranches];

        $verdict = $this->decision->decide($branch, $exempt, $prefixes);
        if ($verdict->passes) {
            $context->writeln('exempt' === $verdict->reason
                ? \sprintf("[branchNamePolicy] PASS — branch '%s' is exempt (default/protected).", $branch)
                : \sprintf("[branchNamePolicy] PASS — branch '%s' matches allowed prefix '%s'.", $branch, (string)$verdict->reason));

            return ToolResultDto::passed();
        }

        $this->failureGuidance($context, $branch, $prefixes, $verdict->isPlanBranch);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf("branch '%s' matches no allowed prefix", $branch));
    }

    /** @param list<string> $prefixes */
    private function failureGuidance(ToolContext $context, string $branch, array $prefixes, bool $isPlanBranch): void
    {
        $context->writeln('');
        $context->writeln('==============================================================================');
        $context->writeln('branchNamePolicy: DISALLOWED BRANCH');
        $context->writeln('==============================================================================');
        $context->writeln('');
        $context->writeln('Current branch: ' . $branch);
        $context->writeln('');
        $context->writeln('Allowed prefixes:');
        foreach ($prefixes as $prefix) {
            $context->writeln('    - ' . $prefix);
        }

        $context->writeln('');
        $context->writeln('Rule:');
        $context->writeln('    A PR represents a new feature or a bug fix, never a single plan.');
        $context->writeln('    Plans land as commits on feature/bugfix branches.');
        $context->writeln('');
        $context->writeln('Full convention: vendor/lts/php-qa-ci/CLAUDE/branch-policy.md');
        $context->writeln('');
        $context->writeln('To extend the allow-list for this project (additive only), create qaConfig/branchNamePolicy.yaml with:');
        $context->writeln('    extra_allowed_prefixes:');
        $context->writeln('      - release/');
        $context->writeln('    extra_exempt_branches:');
        $context->writeln('      - integration');
        $context->writeln('');

        if (!$isPlanBranch) {
            return;
        }

        $context->writeln('==============================================================================');
        $context->writeln('‼  PLAN BRANCH DETECTED — STOP AND READ');
        $context->writeln('==============================================================================');
        $context->writeln('');
        $context->writeln('You are on a plan/* branch. Plans are atomic pieces of work and MUST NOT be the unit of a PR.');
        $context->writeln('A feature/bugfix branch may carry zero or more plans, each landing as one (or more) commit(s).');
        $context->writeln('');
        $context->writeln('What to do:');
        $context->writeln('  1. git checkout -b feature/your-feature-name   # or bugfix/, chore/, hotfix/');
        $context->writeln('  2. Bring your plan commits onto the feature branch (cherry-pick or merge).');
        $context->writeln('  3. Open the PR from the feature branch — never from plan/*.');
        $context->writeln('');
    }
}
