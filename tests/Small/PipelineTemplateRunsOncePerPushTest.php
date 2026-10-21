<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defence for the class "a pull request push that starts the pipeline more than once, or starts it
 * with more access than it needs".
 *
 * A push to a branch with an open pull request raises two events, `push` and `pull_request`. A
 * template that listens to both for the same branch runs the whole pipeline twice for every push
 * and reports a second, unrequired result. The shipped pipeline template therefore listens to
 * `pull_request` for every pull request and to `push` only for the default branch, where the run
 * gates a release; a superseded pull request run is cancelled, a default-branch run never is.
 *
 * The same file is the consumers' required check, so its QA job is named by a constant (a name
 * that follows the PHP version leaves branch protection waiting for a check that never arrives),
 * and its token reads and nothing more: the pipeline runs third-party code with that token.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class PipelineTemplateRunsOncePerPushTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../templates/github-actions/php-qa-ci.yml';

    private const string REPOSITORY = __DIR__ . '/../..';

    /** The job consumers require; branch protection keys a check by this name. */
    private const string QA_JOB_NAME = 'QA Pipeline';

    private const string ON = 'on';

    private const string JOBS = 'jobs';

    private const string STEPS = 'steps';

    private const string PERMISSIONS = 'permissions';

    private const string QA_JOB = 'qa';

    private const string COVERAGE_JOB = 'coverage-report';

    private const string WRITE = 'write';

    #[Test]
    public function thePipelineTemplateRunsForEveryPullRequestAndForAPushToTheDefaultBranchOnly(): void
    {
        $triggers = $this->workflow(self::TEMPLATE)[self::ON] ?? null;
        self::assertIsArray($triggers);

        self::assertSame(['push', 'pull_request', 'workflow_dispatch'], array_keys($triggers));

        $pull = $triggers['pull_request'];
        self::assertTrue(null === $pull || [] === array_diff(array_keys((array)$pull), ['types']), 'A pull_request branch filter would leave a pull request to another base without a run.');

        $push = $triggers['push'];
        self::assertIsArray($push);
        $branches = $push['branches'] ?? null;
        self::assertIsArray($branches, 'push must name the branches it runs for; a bare push runs for every branch a pull request is raised from.');
        self::assertNotSame([], $branches);
        self::assertSame([], $this->branchesFromWorkPrefixes($branches), 'A push to a work branch runs the pipeline a second time beside its pull request.');
        self::assertArrayNotHasKey('tags', $push);
    }

    /** The default branch is where a push gates a release, so this repository's own line is listed. */
    #[Test]
    public function thePushTriggerListsThisRepositorysDefaultBranch(): void
    {
        $push = ($this->workflow(self::TEMPLATE)[self::ON] ?? [])['push'] ?? null;
        self::assertIsArray($push);

        self::assertContains($this->defaultBranchOfThisRepository(), $push['branches'] ?? []);
    }

    #[Test]
    public function aSupersededPullRequestRunIsCancelledButADefaultBranchRunNever(): void
    {
        $concurrency = $this->workflow(self::TEMPLATE)['concurrency'] ?? null;
        self::assertIsArray($concurrency, 'The workflow needs a concurrency group.');

        $group = $concurrency['group'] ?? null;
        self::assertIsString($group);
        self::assertStringContainsString('github.workflow', $group, 'A group is shared by every workflow of the repository unless it names its own.');
        self::assertStringContainsString('github.event.pull_request.number', $group, 'Pushes to one pull request must share a group, or none is superseded.');
        self::assertStringContainsString('github.run_id', $group, 'Any other run needs a group of its own, or a newer push cancels a default-branch run that gates a release.');

        self::assertSame("\${{ github.event_name == 'pull_request' }}", $concurrency['cancel-in-progress'] ?? null);
    }

    #[Test]
    public function theTokenReadsTheRepositoryAndOnlyTheOptInCommentJobWritesAnything(): void
    {
        $workflow = $this->workflow(self::TEMPLATE);
        self::assertSame(['contents' => 'read'], $workflow[self::PERMISSIONS] ?? null);

        $writers = [];
        foreach ($this->jobs($workflow) as $id => $job) {
            $granted = $job[self::PERMISSIONS] ?? [];
            self::assertIsArray($granted);
            if (\in_array(self::WRITE, $granted, true)) {
                $writers[$id] = $granted;
            }
        }

        self::assertSame([self::COVERAGE_JOB => ['contents' => 'read', 'pull-requests' => self::WRITE]], $writers);
    }

    #[Test]
    public function theQaJobIsNamedByAConstantAndDetectsThePhpVersionInAStep(): void
    {
        $workflow = $this->workflow(self::TEMPLATE);
        $jobs     = $this->jobs($workflow);

        self::assertSame(self::QA_JOB_NAME, $jobs[self::QA_JOB]['name'] ?? null);
        self::assertArrayNotHasKey('needs', $jobs[self::QA_JOB], 'The QA job runs first: a job of its own for the PHP version costs a runner and a check name.');
        self::assertArrayNotHasKey('outputs', $jobs[self::QA_JOB]);
        self::assertSame([self::QA_JOB, self::COVERAGE_JOB], array_keys($jobs));

        $setupPhp = $this->stepUsing($jobs[self::QA_JOB], 'shivammathur/setup-php');
        self::assertMatchesRegularExpression('/^\$\{\{ steps\.[a-z-]+\.outputs\.version \}\}$/', (string)($setupPhp['with']['php-version'] ?? ''), 'The PHP version comes from an earlier step of the same job.');

        $detecting = array_values(array_filter(
            $this->steps($jobs[self::QA_JOB]),
            static fn (array $step): bool => str_contains((string)($step['run'] ?? ''), 'composer.json'),
        ));
        self::assertNotSame([], $detecting, 'A step must read composer.json for the PHP version.');
    }

    /** A hung lane (an Infection run was seen to sit for 20 minutes without output) must not hold the check open for hours. */
    #[Test]
    public function theQaJobIsBoundedInTime(): void
    {
        $timeout = $this->jobs($this->workflow(self::TEMPLATE))[self::QA_JOB]['timeout-minutes'] ?? null;

        self::assertIsInt($timeout);
        self::assertLessThanOrEqual(60, $timeout);
    }

    #[Test]
    public function theCoverageReportIsOptInAndNothingWaitsForIt(): void
    {
        $jobs = $this->jobs($this->workflow(self::TEMPLATE));
        $job  = $jobs[self::COVERAGE_JOB] ?? null;
        self::assertIsArray($job);

        $condition = (string)($job['if'] ?? '');
        self::assertStringContainsString('vars.', $condition, 'The report runs only when the project switches it on with a repository variable.');
        self::assertStringContainsString('pull_request', $condition);
        self::assertStringContainsString('github.repository', $condition, 'A pull request from a fork never has a token that can comment.');
        self::assertTrue($job['continue-on-error'] ?? false, 'A comment that cannot be posted must not fail the pull request.');
        self::assertSame(self::QA_JOB, $job['needs'] ?? null);
    }

    #[Test]
    public function theDispatchedToolIsAnOptionOfTheChoiceNotAnEmptyOne(): void
    {
        $dispatch = ($this->workflow(self::TEMPLATE)[self::ON] ?? [])['workflow_dispatch'] ?? null;
        self::assertIsArray($dispatch);

        foreach ($dispatch['inputs'] ?? [] as $name => $input) {
            if ('choice' !== ($input['type'] ?? null)) {
                continue;
            }

            self::assertContainsOnlyString($input['options']);
            self::assertNotContains('', $input['options'], $name . ' offers an empty option, which GitHub rejects.');
            self::assertContains($input['default'] ?? null, $input['options'], $name . ' must default to one of its options.');
        }
    }

    #[Test]
    public function theQaStepIsReadOnlyAndTheTemplateCommitsNothing(): void
    {
        $qa = $this->stepNamed($this->jobs($this->workflow(self::TEMPLATE))[self::QA_JOB], 'Run QA Pipeline');

        self::assertSame('1', (string)($qa['env']['QA_READONLY'] ?? null), 'A read-only token cannot push, and a pushed fix would carry no check of its own.');

        $template = \Safe\file_get_contents(self::TEMPLATE);
        self::assertStringNotContainsString('AUTO_COMMIT_FIXES', $template);
        self::assertSame(0, \Safe\preg_match('/^\s*(?:git push|git commit)\b/m', $template), 'Fixes are committed by the qa-autofix template, whose same-run gate validates them.');
    }

    /**
     * An expression in a shell script is substituted before the shell parses it, so a branch
     * name, an input or a title written by someone else becomes shell syntax. A value reaches a
     * script through `env:` and is read as a variable.
     */
    #[Test]
    public function noScriptOfAWorkflowOrTemplateHoldsAnExpression(): void
    {
        $found = [];
        foreach ($this->workflowFiles() as $file) {
            $parsed = $this->workflow($file);
            array_walk_recursive($parsed, static function (mixed $value, int|string $key) use (&$found, $file): void {
                if ('run' !== $key || !\is_string($value)) {
                    return;
                }

                \Safe\preg_match_all('/\$\{\{.*?\}\}/s', $value, $expressions);
                foreach ($expressions[0] as $expression) {
                    $found[] = basename($file) . ': ' . $expression;
                }
            });
        }

        self::assertSame([], $found, 'Pass the value to the script through env: and read it as a shell variable.');
    }

    /**
     * @param list<mixed> $branches
     *
     * @return list<string> the branch patterns that name a work-branch prefix the branch policy allows
     */
    private function branchesFromWorkPrefixes(array $branches): array
    {
        $prefixes = array_map(static fn (string $prefix): string => rtrim($prefix, '/'), BranchNamePolicyDecision::DEFAULT_PREFIXES);

        return array_values(array_filter(
            array_map(strval(...), $branches),
            static fn (string $branch): bool => '**' === $branch || \in_array(rtrim($branch, '/*'), $prefixes, true),
        ));
    }

    private function defaultBranchOfThisRepository(): string
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::REPOSITORY . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(1, \Safe\preg_match('/(\d+\.\d+)/', (string)($composer['require']['php'] ?? ''), $version));

        return 'php' . $version[1];
    }

    /** @return array<string, mixed> */
    private function workflow(string $file): array
    {
        $workflow = Yaml::parse(\Safe\file_get_contents($file));
        self::assertIsArray($workflow);

        return $workflow;
    }

    /**
     * @param array<string, mixed> $workflow
     *
     * @return array<string, array<string, mixed>>
     */
    private function jobs(array $workflow): array
    {
        $jobs = $workflow[self::JOBS] ?? null;
        self::assertIsArray($jobs);

        return $jobs;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return list<array<string, mixed>>
     */
    private function steps(array $job): array
    {
        $steps = $job[self::STEPS] ?? null;
        self::assertIsArray($steps);
        self::assertTrue(array_is_list($steps));

        return $steps;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function stepUsing(array $job, string $action): array
    {
        foreach ($this->steps($job) as $step) {
            if (str_starts_with((string)($step['uses'] ?? ''), $action . '@')) {
                return $step;
            }
        }

        self::fail('No step uses ' . $action);
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function stepNamed(array $job, string $name): array
    {
        foreach ($this->steps($job) as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        self::fail('No step is named ' . $name);
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [
            ...\Safe\glob(self::REPOSITORY . '/.github/workflows/*.yml'),
            ...\Safe\glob(self::REPOSITORY . '/.github/actions/*/action.yml'),
            ...\Safe\glob(self::REPOSITORY . '/templates/github-actions/*.yml'),
            ...\Safe\glob(self::REPOSITORY . '/templates/github-actions/*/action.yml'),
        ];
        self::assertContainsOnlyString($files);
        sort($files);

        return $files;
    }
}
