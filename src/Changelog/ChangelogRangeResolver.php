<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ChangelogRangeDto;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;

/**
 * Where "the changes this check judges" start.
 *
 * - A pull request build (GitHub sets GITHUB_BASE_REF) and any branch other
 *   than the default: the merge base of HEAD with the target branch, taken
 *   from `origin/<branch>` when the clone has it, else the local branch.
 * - The default branch, including GitHub's detached checkout of a push to it
 *   (GITHUB_REF_NAME): the latest release tag on the line composer.json names,
 *   because everything since that release is what the next one will ship.
 *
 * Any answer the history cannot give (no default branch, a detached HEAD
 * outside a pull request, a branch or tag the clone lacks, no merge base) is a
 * ChangelogHistoryException naming the fetch that would supply it.
 *
 * @api
 */
final readonly class ChangelogRangeResolver
{
    private const string FETCH_ALL = '`fetch-depth: 0` on actions/checkout';

    public function __construct(private ReleaseVersionCalculator $calculator = new ReleaseVersionCalculator())
    {
    }

    public function resolve(ChangelogGit $git, GitBranches $branches, EnvironmentReader $env, string $composerJson): ChangelogRangeDto
    {
        $pullRequestTarget = $env->string('GITHUB_BASE_REF');
        if (null !== $pullRequestTarget) {
            return $this->sinceBranchPoint($git, $pullRequestTarget);
        }

        $default = $branches->defaultBranch() ?? throw new ChangelogHistoryException(
            'cannot tell the default branch: refs/remotes/origin/HEAD is unset and `git ls-remote --symref origin HEAD` gave no answer; run `git remote set-head origin --auto`',
        );

        $current = $branches->currentBranch();
        if ($default === $current || (null === $current && $default === $env->string('GITHUB_REF_NAME'))) {
            return $this->sinceLastRelease($git, $composerJson);
        }

        if (null === $current) {
            throw new ChangelogHistoryException('HEAD is detached and this is not a pull request build (GITHUB_BASE_REF is unset), so there is no branch to measure the change from; check out a branch');
        }

        return $this->sinceBranchPoint($git, $default);
    }

    private function sinceBranchPoint(ChangelogGit $git, string $branch): ChangelogRangeDto
    {
        foreach (['origin/' . $branch, $branch] as $ref) {
            if (!$git->resolves($ref)) {
                continue;
            }

            $base = $git->mergeBase($ref) ?? throw new ChangelogHistoryException(\sprintf(
                'HEAD and %s share no merge base in this clone, so the history is incomplete; fetch it (`git fetch --unshallow`, or %s)',
                $ref,
                self::FETCH_ALL,
            ));

            return new ChangelogRangeDto($base, \sprintf('since the merge base with %s (%s)', $ref, $base));
        }

        throw new ChangelogHistoryException(\sprintf(
            'neither origin/%1$s nor %1$s is in this clone, so the changes since the branch point cannot be found; fetch it (`git fetch origin %1$s`, or %2$s)',
            $branch,
            self::FETCH_ALL,
        ));
    }

    private function sinceLastRelease(ChangelogGit $git, string $composerJson): ChangelogRangeDto
    {
        $major = $this->calculator->lineMajor($composerJson);
        $tag   = $this->calculator->latestOnLine($major, ...$git->tags()) ?? throw new ChangelogHistoryException(\sprintf(
            'no %1$d.N.N release tag is in this clone, so the changes since the last release cannot be found; fetch the tags (`git fetch --tags`, or %2$s), or tag the first release %1$d.0.0',
            $major,
            self::FETCH_ALL,
        ));

        return new ChangelogRangeDto($tag, 'since the last release tag ' . $tag);
    }
}
