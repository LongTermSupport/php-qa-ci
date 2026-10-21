<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a job that runs third-party code holding a token that can write".
 *
 * The dependency update runs `composer update`, which executes every installed plugin and script,
 * and then the whole pipeline over the result. Those steps must run with a token that reads
 * and nothing more, so a compromised dependency cannot push, open a pull request or change an
 * issue. The job that holds the write grants only applies the already verified change and opens
 * the pull request; it installs and runs nothing from the repository.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class UpdateDepsWriteTokenIsConfinedTest extends TestCase
{
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/update-deps.yml';

    private const string WRITE = 'write';

    private const string PERMISSIONS = 'permissions';

    private const string STEPS = 'steps';

    // Steps that execute code the repository or its dependencies provide.
    private const string RUNS_PROJECT_CODE = '/\b(?:composer (?:update|install)|ci\.bash|bin\/|php |bash scripts\/|vendor\/)/';

    #[Test]
    public function theWorkflowGrantsNothingButReadAccess(): void
    {
        self::assertSame(['contents' => 'read'], $this->workflow()->child(self::PERMISSIONS)->stringMap());
    }

    #[Test]
    public function noJobThatRunsProjectCodeCanWrite(): void
    {
        $runners = array_filter($this->jobs(), $this->runsProjectCode(...));
        self::assertNotSame([], $runners, 'The update job must be found.');

        $writers = array_keys(array_filter(
            $runners,
            static fn (WorkflowNode $job): bool => \in_array(self::WRITE, $job->child(self::PERMISSIONS)->stringMap(), true),
        ));

        self::assertSame([], $writers);
    }

    #[Test]
    public function theJobThatOpensThePullRequestIsTheOnlyOneWithWriteAccessToIt(): void
    {
        $opening = $this->jobsWithAStepUsing('peter-evans/create-pull-request@');

        self::assertCount(1, $opening);
        $job = array_first($opening);
        self::assertFalse($this->runsProjectCode($job), "The job that holds the write token must not run the repository's code.");
        self::assertSame(['contents' => self::WRITE, 'pull-requests' => self::WRITE], $job->child(self::PERMISSIONS)->stringMap());
    }

    /**
     * The approval runs the repository's own action, so it runs in a job that never applied the
     * update: a patch that altered the action would otherwise run with the grant that approves
     * workflow runs.
     */
    #[Test]
    public function theApprovalOfTheHeldRunIsAJobThatAppliesNothing(): void
    {
        $approving = $this->jobsWithAStepUsing('./.github/actions/approve-held-ci');

        self::assertCount(1, $approving);
        $job = array_first($approving);
        self::assertSame(['actions' => self::WRITE, 'contents' => 'read'], $job->child(self::PERMISSIONS)->stringMap());
        self::assertSame([], $this->jobsApplyingTheUpdate($job), 'A job holding actions: write must not apply the update.');
    }

    #[Test]
    public function aFailedRunReachesTheTrackingIssueFromAJobThatWritesNothingElse(): void
    {
        $reporting = array_filter($this->jobs(), static fn (WorkflowNode $job): bool => [] !== array_filter(
            $job->child(self::STEPS)->items(),
            static fn (WorkflowNode $step): bool => str_contains($step->child('with', 'script')->text(), 'update-deps-failure'),
        ));

        self::assertCount(1, $reporting);
        $job = array_first($reporting);
        self::assertSame(['issues' => self::WRITE], $job->child(self::PERMISSIONS)->stringMap());
        self::assertFalse($this->runsProjectCode($job));
    }

    private function workflow(): WorkflowNode
    {
        return WorkflowNode::ofFile(self::WORKFLOW);
    }

    /** @return array<array-key, WorkflowNode> */
    private function jobs(): array
    {
        return $this->workflow()->child('jobs')->entries();
    }

    /** @return array<array-key, WorkflowNode> the jobs with a step that uses an action beginning with this reference */
    private function jobsWithAStepUsing(string $reference): array
    {
        return array_filter($this->jobs(), static fn (WorkflowNode $job): bool => [] !== array_filter(
            $job->child(self::STEPS)->items(),
            static fn (WorkflowNode $step): bool => str_starts_with($step->child('uses')->text(), $reference),
        ));
    }

    /**
     * @return list<WorkflowNode> those that download an artifact or apply a patch
     */
    private function jobsApplyingTheUpdate(WorkflowNode ...$jobs): array
    {
        return array_values(array_filter($jobs, static fn (WorkflowNode $job): bool => [] !== array_filter(
            $job->child(self::STEPS)->items(),
            static fn (WorkflowNode $step): bool => str_contains($step->child('uses')->text(), 'download-artifact') || str_contains($step->child('run')->text(), 'git apply'),
        )));
    }

    private function runsProjectCode(WorkflowNode $job): bool
    {
        return array_any($job->child(self::STEPS)->items(), static fn (WorkflowNode $step): bool => 1 === \Safe\preg_match(self::RUNS_PROJECT_CODE, $step->child('run')->text()));
    }
}
