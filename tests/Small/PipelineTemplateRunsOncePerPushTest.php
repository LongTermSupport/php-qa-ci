<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    private const string QA_JOB_NAME = 'QA Pipeline';

    private const string QA_JOB = 'qa';

    private const string COVERAGE_JOB = 'coverage-report';

    private const string WRITE = 'write';

    private const string JOBS = 'jobs';

    private const string STEPS = 'steps';

    private const string PUSH = 'push';

    private const string PULL_REQUEST = 'pull_request';

    #[Test]
    public function thePipelineTemplateRunsForEveryPullRequestAndForAPushToTheDefaultBranchOnly(): void
    {
        $triggers = $this->template()->child('on');

        self::assertSame([self::PUSH, self::PULL_REQUEST, 'workflow_dispatch'], $triggers->keys());
        self::assertSame(['types'], $triggers->child(self::PULL_REQUEST)->keys(), 'A pull_request branch filter would leave a pull request to another base without a run.');

        $branches = $triggers->child(self::PUSH, 'branches')->strings();
        self::assertNotSame([], $branches, 'push must name the branches it runs for; a bare push runs for every branch a pull request is raised from.');
        self::assertSame([], $this->workBranchPatterns(...$branches), 'A push to a work branch runs the pipeline a second time beside its pull request.');
        self::assertFalse($triggers->child(self::PUSH)->has('tags'));
    }

    /** The default branch is where a push gates a release, so this repository's own line is listed. */
    #[Test]
    public function thePushTriggerListsThisRepositorysDefaultBranch(): void
    {
        self::assertContains($this->defaultBranchOfThisRepository(), $this->template()->child('on', self::PUSH, 'branches')->strings());
    }

    #[Test]
    public function aSupersededPullRequestRunIsCancelledButADefaultBranchRunNever(): void
    {
        $concurrency = $this->template()->child('concurrency');
        $group       = $concurrency->child('group')->string();

        self::assertNotNull($group, 'The workflow needs a concurrency group.');
        self::assertStringContainsString('github.workflow', $group, 'A group is shared by every workflow of the repository unless it names its own.');
        self::assertStringContainsString('github.event.pull_request.number', $group, 'Pushes to one pull request must share a group, or none is superseded.');
        self::assertStringContainsString('github.run_id', $group, 'Any other run needs a group of its own, or a newer push cancels a default-branch run that gates a release.');
        self::assertSame("\${{ github.event_name == 'pull_request' }}", $concurrency->child('cancel-in-progress')->string());
    }

    #[Test]
    public function theTokenReadsTheRepositoryAndOnlyTheOptInCommentJobWritesAnything(): void
    {
        self::assertSame(['contents' => 'read'], $this->template()->child('permissions')->stringMap());

        $writers = [];
        foreach ($this->template()->child(self::JOBS)->entries() as $id => $job) {
            $granted = $job->child('permissions')->stringMap();
            if (\in_array(self::WRITE, $granted, true)) {
                $writers[$id] = $granted;
            }
        }

        self::assertSame([self::COVERAGE_JOB => ['contents' => 'read', 'pull-requests' => self::WRITE]], $writers);
    }

    #[Test]
    public function theQaJobIsNamedByAConstantAndDetectsThePhpVersionInAStep(): void
    {
        $jobs = $this->template()->child(self::JOBS);
        $qa   = $jobs->child(self::QA_JOB);

        self::assertSame([self::QA_JOB, self::COVERAGE_JOB], $jobs->keys(), 'The QA job runs first: a job of its own for the PHP version costs a runner and a check name.');
        self::assertSame(self::QA_JOB_NAME, $qa->child('name')->string());
        self::assertFalse($qa->has('needs'));
        self::assertFalse($qa->has('outputs'));

        $setupPhp = array_values(array_filter(
            $qa->child(self::STEPS)->items(),
            static fn (WorkflowNode $step): bool => str_starts_with($step->child('uses')->text(), 'shivammathur/setup-php@'),
        ));
        self::assertCount(1, $setupPhp);
        self::assertMatchesRegularExpression('/^\$\{\{ steps\.[a-z-]+\.outputs\.version \}\}$/', $setupPhp[0]->child('with', 'php-version')->text(), 'The PHP version comes from an earlier step of the same job.');

        $detecting = array_filter($qa->child(self::STEPS)->items(), static fn (WorkflowNode $step): bool => str_contains($step->child('run')->text(), 'composer.json'));
        self::assertNotSame([], $detecting, 'A step must read composer.json for the PHP version.');
    }

    /** A hung lane (an Infection run was seen to sit for 20 minutes without output) must not hold the check open for hours. */
    #[Test]
    public function theQaJobIsBoundedInTime(): void
    {
        $timeout = $this->template()->child(self::JOBS, self::QA_JOB, 'timeout-minutes')->integer();

        self::assertNotNull($timeout);
        self::assertLessThanOrEqual(60, $timeout);
    }

    #[Test]
    public function theCoverageReportIsOptInAndNothingWaitsForIt(): void
    {
        $job       = $this->template()->child(self::JOBS, self::COVERAGE_JOB);
        $condition = $job->child('if')->text();

        self::assertStringContainsString('vars.', $condition, 'The report runs only when the project switches it on with a repository variable.');
        self::assertStringContainsString(self::PULL_REQUEST, $condition);
        self::assertStringContainsString('github.repository', $condition, 'A pull request from a fork never has a token that can comment.');
        self::assertTrue($job->child('continue-on-error')->boolean(), 'A comment that cannot be posted must not fail the pull request.');
        self::assertSame(self::QA_JOB, $job->child('needs')->string());
    }

    #[Test]
    public function theDispatchedToolIsAnOptionOfTheChoiceNotAnEmptyOne(): void
    {
        $inputs = $this->template()->child('on', 'workflow_dispatch', 'inputs')->entries();
        self::assertNotSame([], $inputs);

        foreach ($inputs as $name => $input) {
            if ('choice' !== $input->child('type')->string()) {
                continue;
            }

            $options = $input->child('options')->strings();
            self::assertNotSame([], $options);
            self::assertNotContains('', $options, $name . ' offers an empty option, which GitHub rejects.');
            self::assertContains($input->child('default')->string(), $options, $name . ' must default to one of its options.');
        }
    }

    #[Test]
    public function theQaStepIsReadOnlyAndTheTemplateCommitsNothing(): void
    {
        $steps = array_values(array_filter(
            $this->template()->child(self::JOBS, self::QA_JOB, self::STEPS)->items(),
            static fn (WorkflowNode $step): bool => 'Run QA Pipeline' === $step->child('name')->string(),
        ));
        self::assertCount(1, $steps);
        self::assertSame('1', $steps[0]->child('env', 'QA_READONLY')->string(), 'A read-only token cannot push, and a pushed fix would carry no check of its own.');

        $template = \Safe\file_get_contents(self::TEMPLATE);
        self::assertStringNotContainsString('AUTO_COMMIT_FIXES', $template);
        self::assertSame(0, \Safe\preg_match('/^\s*(?:git push|git commit)\b/m', $template), 'Fixes are committed by the qa-autofix template, whose same-run gate validates them.');
    }

    /**
     * A push run on the default branch has no pull request to mutation-test, and a full Infection run
     * outlasts the job's time limit there, so no release is ever prepared. Infection stays on for every
     * pull request run, where it covers the diff.
     */
    #[Test]
    public function infectionIsSkippedOnPushAndRunsOnEveryOtherEvent(): void
    {
        foreach ([self::TEMPLATE, self::REPOSITORY . '/.github/workflows/qa.yml'] as $file) {
            $steps = array_values(array_filter(
                WorkflowNode::ofFile($file)->child(self::JOBS, self::QA_JOB, self::STEPS)->items(),
                static fn (WorkflowNode $step): bool => 'Run QA Pipeline' === $step->child('name')->string(),
            ));
            self::assertCount(1, $steps, $file);
            self::assertSame(
                "\${{ github.event_name == 'push' && '0' || '1' }}",
                $steps[0]->child('env', 'useInfection')->string(),
                basename($file) . ' must skip Infection on push and keep it for pull requests.',
            );
        }
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
            foreach (WorkflowNode::ofFile($file)->stringsUnderKey('run') as $script) {
                foreach ($this->expressionsIn($script) as $expression) {
                    $found[] = basename($file) . ': ' . $expression;
                }
            }
        }

        self::assertSame([], $found, 'Pass the value to the script through env: and read it as a shell variable.');
    }

    /**
     * @return list<string> the branch patterns that name a work-branch prefix the branch policy allows
     */
    private function workBranchPatterns(string ...$branches): array
    {
        $prefixes = array_map(static fn (string $prefix): string => rtrim($prefix, '/'), BranchNamePolicyDecision::DEFAULT_PREFIXES);

        return array_values(array_filter(
            $branches,
            static fn (string $branch): bool => '**' === $branch || \in_array(rtrim($branch, '/*'), $prefixes, true),
        ));
    }

    /** @return list<string> */
    private function expressionsIn(string $script): array
    {
        $matches = [];
        \Safe\preg_match_all('/\$\{\{.*?\}\}/s', $script, $matches);

        $captures = $matches[0] ?? [];

        return \is_array($captures) ? array_values(array_filter($captures, is_string(...))) : [];
    }

    private function defaultBranchOfThisRepository(): string
    {
        $composer = WorkflowNode::ofJson(\Safe\file_get_contents(self::REPOSITORY . '/composer.json'));
        $version  = [];
        self::assertSame(1, \Safe\preg_match('/(\d+\.\d+)/', $composer->child('require', 'php')->text(), $version), 'composer.json states no PHP version.');
        $majorMinor = $version[1] ?? null;
        self::assertNotNull($majorMinor);

        return 'php' . $majorMinor;
    }

    private function template(): WorkflowNode
    {
        return WorkflowNode::ofFile(self::TEMPLATE);
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
