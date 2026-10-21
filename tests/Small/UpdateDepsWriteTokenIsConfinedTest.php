<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    private const string JOBS = 'jobs';

    private const string PERMISSIONS = 'permissions';

    private const string STEPS = 'steps';

    private const string WRITE = 'write';

    /** Steps that execute code the repository or its dependencies provide. */
    private const string RUNS_PROJECT_CODE = '/\b(?:composer (?:update|install)|ci\.bash|bin\/|php |bash scripts\/|vendor\/)/';

    #[Test]
    public function theWorkflowGrantsNothingButReadAccess(): void
    {
        self::assertSame(['contents' => 'read'], $this->workflow()[self::PERMISSIONS] ?? null);
    }

    #[Test]
    public function noJobThatRunsProjectCodeCanWrite(): void
    {
        $writers = [];
        foreach ($this->jobs() as $id => $job) {
            if ($this->runsProjectCode($job) && \in_array(self::WRITE, (array)($job[self::PERMISSIONS] ?? []), true)) {
                $writers[] = $id;
            }
        }

        self::assertSame([], $writers);
        self::assertNotSame([], array_filter($this->jobs(), $this->runsProjectCode(...)), 'The update job must be found.');
    }

    #[Test]
    public function theJobThatOpensThePullRequestIsTheOnlyOneWithWriteAccessToIt(): void
    {
        $opening = [];
        foreach ($this->jobs() as $id => $job) {
            foreach ($this->stepsOf($job) as $step) {
                if (str_starts_with((string)($step['uses'] ?? ''), 'peter-evans/create-pull-request@')) {
                    $opening[] = $id;
                }
            }
        }

        self::assertCount(1, $opening);
        $job = $this->jobs()[$opening[0]];
        self::assertFalse($this->runsProjectCode($job), 'The job that holds the write token must not run the repository\'s code.');
        self::assertSame(['contents' => self::WRITE, 'pull-requests' => self::WRITE], $job[self::PERMISSIONS] ?? null);
    }

    /**
     * The approval runs the repository's own action, so it runs in a job that never applied the
     * update: a patch that altered the action would otherwise run with the grant that approves
     * workflow runs.
     */
    #[Test]
    public function theApprovalOfTheHeldRunIsAJobThatAppliesNothing(): void
    {
        $approving = array_filter($this->jobs(), fn (array $job): bool => array_filter(
            $this->stepsOf($job),
            static fn (array $step): bool => str_ends_with((string)($step['uses'] ?? ''), '/approve-held-ci'),
        ) !== []);

        self::assertCount(1, $approving);
        $job = array_values($approving)[0];
        self::assertSame(['actions' => self::WRITE, 'contents' => 'read'], $job[self::PERMISSIONS] ?? null);
        self::assertSame([], array_filter(
            $this->stepsOf($job),
            static fn (array $step): bool => str_contains((string)($step['uses'] ?? ''), 'download-artifact') || str_contains((string)($step['run'] ?? ''), 'git apply'),
        ), 'A job holding actions: write must not apply the update.');
    }

    #[Test]
    public function aFailedRunReachesTheTrackingIssueFromAJobThatWritesNothingElse(): void
    {
        $reporting = array_filter($this->jobs(), fn (array $job): bool => array_filter(
            $this->stepsOf($job),
            static fn (array $step): bool => str_contains((string)($step['with']['script'] ?? ''), 'update-deps-failure'),
        ) !== []);

        self::assertCount(1, $reporting);
        $job = array_values($reporting)[0];
        self::assertSame(['contents' => 'read', 'issues' => self::WRITE], $job[self::PERMISSIONS] ?? null);
        self::assertFalse($this->runsProjectCode($job));
    }

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        $workflow = Yaml::parse(\Safe\file_get_contents(self::WORKFLOW));
        self::assertIsArray($workflow);

        return $workflow;
    }

    /** @return array<string, array<string, mixed>> */
    private function jobs(): array
    {
        $jobs = $this->workflow()[self::JOBS] ?? null;
        self::assertIsArray($jobs);

        return $jobs;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return list<array<string, mixed>>
     */
    private function stepsOf(array $job): array
    {
        $steps = $job[self::STEPS] ?? [];
        self::assertIsArray($steps);

        return array_values($steps);
    }

    /** @param array<string, mixed> $job */
    private function runsProjectCode(array $job): bool
    {
        foreach ($this->stepsOf($job) as $step) {
            if (1 === \Safe\preg_match(self::RUNS_PROJECT_CODE, (string)($step['run'] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
