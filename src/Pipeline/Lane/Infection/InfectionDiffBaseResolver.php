<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\InfectionDiffModeEnum;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffBaseDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * Decides what an Infection run mutates.
 *
 * - Ref: the configured base, strictly (a dirty src/tests tree refuses).
 * - Full: everything.
 * - Auto: a pull request build (GITHUB_BASE_REF) and any branch other than
 *   the default one diff against the merge base of HEAD with the target
 *   branch, read from `origin/<branch>` when the clone has it, else the local
 *   branch; the default branch is the one the branchNamePolicy lane detects.
 *   On the default branch, or when no merge base can be found (a detached
 *   HEAD outside a pull request, an unknown default branch, a branch the
 *   clone lacks, a shallow clone), the run is full and the description says
 *   why, so a run is never narrower than it claims.
 *
 * @internal
 */
final readonly class InfectionDiffBaseResolver
{
    private const string FULL_RUN = 'Infection: full run — auto diff mode does not apply: ';

    public function resolve(InfectionOptionsDto $options, ProcessRunnerInterface $processes, string $projectRoot, EnvironmentReader $env): InfectionDiffBaseDto
    {
        if (null !== $options->diffBase) {
            return InfectionDiffBaseDto::diff(
                $options->diffBase,
                true,
                \sprintf("Infection: diff mode against the configured base '%s' (withInfectionDiffBase / infectionDiffBase), committed history only.", $options->diffBase),
            );
        }

        if (InfectionDiffModeEnum::Full === $options->diffMode) {
            return InfectionDiffBaseDto::fullRun('Infection: full run — every source file is mutated (withInfectionFullRun() / infectionDiffBase=full).');
        }

        $pullRequestTarget = $env->string('GITHUB_BASE_REF');
        if (null !== $pullRequestTarget) {
            return $this->sinceBranchPoint($processes, $projectRoot, $pullRequestTarget, \sprintf("pull request into '%s'", $pullRequestTarget));
        }

        $branches = new GitBranches($processes, $projectRoot);
        $current  = $branches->currentBranch();
        if (null === $current) {
            return InfectionDiffBaseDto::fullRun(self::FULL_RUN . 'HEAD is detached and this is not a pull request build (GITHUB_BASE_REF is unset), so there is no branch to diff.');
        }

        $default = $branches->defaultBranch();
        if (null === $default) {
            return InfectionDiffBaseDto::fullRun(self::FULL_RUN . 'the default branch cannot be told (refs/remotes/origin/HEAD is unset and `git ls-remote --symref origin HEAD` gave no answer; `git remote set-head origin --auto` fixes it).');
        }

        if ($default === $current) {
            return InfectionDiffBaseDto::fullRun(\sprintf("%son the default branch '%s'.", self::FULL_RUN, $default));
        }

        return $this->sinceBranchPoint($processes, $projectRoot, $default, \sprintf("branch '%s'", $current));
    }

    private function sinceBranchPoint(ProcessRunnerInterface $processes, string $projectRoot, string $branch, string $subject): InfectionDiffBaseDto
    {
        foreach (['origin/' . $branch, $branch] as $ref) {
            if (!$this->git($processes, $projectRoot, 'rev-parse', '--verify', '--quiet', $ref . '^{commit}')->succeeded()) {
                continue;
            }

            $result = $this->git($processes, $projectRoot, 'merge-base', 'HEAD', $ref);
            $base   = trim($result->stdout);
            if (!$result->succeeded() || '' === $base) {
                return InfectionDiffBaseDto::fullRun(\sprintf('%sHEAD and %s share no merge base in this clone, so the history is incomplete (a shallow clone?); `git fetch --unshallow`, or `fetch-depth: 0` on actions/checkout, restores diff mode.', self::FULL_RUN, $ref));
            }

            return InfectionDiffBaseDto::diff($base, false, \sprintf('Infection: auto diff mode — %s against %s (merge base %s).', $subject, $ref, $base));
        }

        return InfectionDiffBaseDto::fullRun(\sprintf('%1$sneither origin/%2$s nor %2$s is in this clone; `git fetch origin %2$s` restores diff mode.', self::FULL_RUN, $branch));
    }

    private function git(ProcessRunnerInterface $processes, string $projectRoot, string ...$args): ProcessResultDto
    {
        return $processes->run(new ProcessSpecDto(['git', ...array_values($args)], $projectRoot, streamOutput: false));
    }
}
