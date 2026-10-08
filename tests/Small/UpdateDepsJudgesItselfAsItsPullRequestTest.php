<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a job that verifies a change in a different context from the one its
 * pull request is verified in".
 *
 * The update-deps workflow runs the full pipeline on the default branch's checkout, then opens a
 * pull request. On the default branch the changelog lane judges everything since the last release
 * tag, so any entry already under "## Unreleased" covers the update's own changes; on the pull
 * request it judges only the change since the merge base. The job passed and its pull request
 * failed (#52). The QA run must therefore happen on a work branch, where the lane measures from the
 * merge base as it will on the pull request, and the job must return to the default branch before
 * the pull request is created from the working tree.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class UpdateDepsJudgesItselfAsItsPullRequestTest extends TestCase
{
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/update-deps.yml';

    /** The full pipeline. */
    private const string QA = 'bash ci.bash';

    /** Onto a work branch with the update still uncommitted, so the lane diffs it against the merge base. */
    private const string TO_WORK_BRANCH = 'git switch --create chore/update-deps';

    /** Back to the branch the job checked out, which the pull request action bases on. */
    private const string BACK = 'git switch -';

    /** The step that opens the pull request. */
    private const string CREATE_PR = 'name: Create Pull Request';

    public function testTheUpdateRunsItsQaOnAWorkBranchAndReturnsBeforeOpeningThePullRequest(): void
    {
        self::assertSame([], $this->problems(\Safe\file_get_contents(self::WORKFLOW)));
    }

    /** The guard proven to fire: QA on the default branch, and a job left on the work branch. */
    public function testTheGuardReportsQaOutsideAWorkBranchOrNoReturn(): void
    {
        $qa     = "          bash ci.bash\n";
        $switch = "          git switch --create chore/update-deps\n";
        $back   = "          git switch -\n";
        $create = "      - name: Create Pull Request\n";

        self::assertSame(['the QA run is not on a work branch'], $this->problems($qa . $back . $create));
        self::assertSame(['the job does not return to its checkout before opening the pull request'], $this->problems($switch . $qa . $create));
        self::assertSame(['the job does not return to its checkout before opening the pull request'], $this->problems($switch . $qa . $create . $back));
        self::assertSame([], $this->problems($switch . $qa . $back . $create));
    }

    /** @return list<string> */
    private function problems(string $workflow): array
    {
        $qa       = strpos($workflow, self::QA);
        $switch   = strpos($workflow, self::TO_WORK_BRANCH);
        $create   = strpos($workflow, self::CREATE_PR);
        $problems = [];

        if (false === $qa || false === $switch || $switch > $qa) {
            $problems[] = 'the QA run is not on a work branch';
        }

        $back = false === $qa ? false : strpos($workflow, self::BACK, $qa);
        if (false === $back || false === $create || $back > $create) {
            $problems[] = 'the job does not return to its checkout before opening the pull request';
        }

        return $problems;
    }
}
