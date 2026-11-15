<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use Iterator;
use LTS\PHPQA\VersionPins\WorkflowBaseGateDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowBaseGateDetector::class)]
#[Small]
final class WorkflowBaseGateDetectorTest extends TestCase
{
    private const string GATE_STEP = <<<'YAML'
          - name: Require the branch to be up to date with its base
            if: github.event_name == 'pull_request'
            env:
              BASE_REF: ${{ github.event.pull_request.base.ref }}
              HEAD_SHA: ${{ github.event.pull_request.head.sha }}
            run: git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"

        YAML;

    private const string QA_JOB = "on:\n  pull_request:\njobs:\n  qa:\n    steps:\n";

    private const string GATE_JOB = "on:\n  pull_request:\njobs:\n  base-gate:\n    steps:\n";

    private const string EXPENSIVE_STEPS =<<<'YAML'
          - uses: shivammathur/setup-php@v2
          - run: composer install

        YAML;

    private const string TARGET_TRIGGER = "on:\n  pull_request_target:\njobs:\n";

    private const string TARGET_QA_JOB = self::TARGET_TRIGGER . "  qa:\n    steps:\n";

    private const string TARGET_GATE_JOB = self::TARGET_TRIGGER . "  base-gate:\n    steps:\n";

    private const string TARGET_GATE_STEP = <<<'YAML'
          - name: Require the branch to be up to date with its base
            env:
              BASE_REF: ${{ github.event.pull_request.base.ref }}
              PR_NUMBER: ${{ github.event.pull_request.number }}
            run: |
              git fetch origin "${BASE_REF}"
              git fetch origin "pull/${PR_NUMBER}/head"
              git merge-base --is-ancestor "origin/${BASE_REF}" FETCH_HEAD

        YAML;

    private const string TARGET_NO_GATE = 'runs on pull_request_target and installs or runs code but has no up-to-date-with-base gate: add a step that fetches the base branch and the pull request head without checking the head out (`git fetch origin "${BASE_REF}"`, then `git fetch origin "pull/${PR_NUMBER}/head"`) and fails when `git merge-base --is-ancestor "origin/${BASE_REF}" FETCH_HEAD` is false, with BASE_REF set from github.event.pull_request.base.ref and PR_NUMBER from github.event.pull_request.number; do not condition the step on `github.event_name == \'pull_request\'`, which is false on this event';

    private const string TARGET_GATE_NEVER_RUNS = 'its up-to-date-with-base gate is conditioned on `github.event_name == \'pull_request\'`, which is false on pull_request_target, so the gate never runs: drop the condition or name pull_request_target in it';

    private const string GATE_AFTER_EXPENSIVE = 'job "qa" runs its gate after its first expensive step; move the gate ahead of PHP setup and composer install';

    private WorkflowBaseGateDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new WorkflowBaseGateDetector();
    }

    #[Test]
    public function aWorkflowWithoutAPullRequestTriggerNeedsNoGate(): void
    {
        $yaml = "on:\n  push:\n    branches: [main]\njobs:\n  qa:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertFalse($this->detector->runsOnPullRequest($yaml));
        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aPullRequestJobWithNoGateIsReported(): void
    {
        $yaml = self::QA_JOB . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['runs on pull_request but has no up-to-date-with-base gate: add a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"` is false, with BASE_REF set from github.event.pull_request.base.ref'],
            $this->detector->check($yaml),
        );
    }

    #[Test]
    public function aFlowStyleTriggerListIsRecognised(): void
    {
        self::assertTrue($this->detector->runsOnPullRequest("on: [push, pull_request]\njobs:\n  qa:\n    steps: []\n"));
    }

    #[Test]
    public function aGateAheadOfTheExpensiveStepsInTheSameJobPasses(): void
    {
        $yaml = self::QA_JOB . self::GATE_STEP . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aGateAfterTheExpensiveStepsIsReported(): void
    {
        $yaml = self::QA_JOB . self::EXPENSIVE_STEPS . self::GATE_STEP;

        self::assertSame(
            ['job "qa" runs its gate after its first expensive step; move the gate ahead of PHP setup and composer install'],
            $this->detector->check($yaml),
        );
    }

    #[Test]
    public function aGateJobThatEveryExpensiveJobNeedsPasses(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  fix:\n    needs: base-gate\n    steps:\n" . self::EXPENSIVE_STEPS
            . "  check:\n    needs: [base-gate, fix]\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aJobReachingTheGateThroughAnotherJobPasses(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  fix:\n    needs: base-gate\n    steps:\n" . self::EXPENSIVE_STEPS
            . "  check:\n    needs: fix\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function anExpensiveJobThatDoesNotNeedTheGateIsReported(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  other:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['job "other" runs on pull_request without depending on the up-to-date-with-base gate; add the gate job (base-gate) to its needs'],
            $this->detector->check($yaml),
        );
    }

    #[Test]
    public function aJobThatRunsNothingExpensiveNeedsNoGate(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  tidy:\n    steps:\n      - run: echo hello\n";

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aWorkflowWithoutAJobsSectionHasNothingToGate(): void
    {
        self::assertSame([], $this->detector->check("on:\n  pull_request:\n"));
    }

    #[Test]
    public function aPullRequestTargetJobThatInstallsAndHasNoGateIsReported(): void
    {
        self::assertSame([self::TARGET_NO_GATE], $this->detector->check(self::TARGET_QA_JOB . self::EXPENSIVE_STEPS));
    }

    #[Test]
    #[DataProvider('pullRequestTargetTriggerShapes')]
    public function aPullRequestTargetTriggerIsRecognisedInEveryShapeItCanBeWritten(string $trigger): void
    {
        $yaml = $trigger . "jobs:\n  qa:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([self::TARGET_NO_GATE], $this->detector->check($yaml));
    }

    /** @return Iterator<string, array{string}> */
    public static function pullRequestTargetTriggerShapes(): Iterator
    {
        yield 'a mapping' => ["on:\n  pull_request_target:\n"];

        yield 'a scalar' => ["on: pull_request_target\n"];

        yield 'a flow list' => ["on: [push, pull_request_target]\n"];

        yield 'a mapping with types' => ["on:\n  pull_request_target:\n    types: [opened]\n"];
    }

    #[Test]
    public function aGateConditionedOnThePullRequestEventNeverRunsOnPullRequestTarget(): void
    {
        $yaml = self::TARGET_QA_JOB . self::GATE_STEP . self::EXPENSIVE_STEPS;

        self::assertSame([self::TARGET_GATE_NEVER_RUNS], $this->detector->check($yaml));
    }

    #[Test]
    public function aPullRequestTargetExpensiveJobThatDoesNotNeedTheGateIsReported(): void
    {
        $yaml = self::TARGET_GATE_JOB . self::TARGET_GATE_STEP
            . "  other:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['job "other" runs on pull_request_target without depending on the up-to-date-with-base gate; add the gate job (base-gate) to its needs'],
            $this->detector->check($yaml),
        );
    }

    #[Test]
    public function aPullRequestTargetGateAfterTheExpensiveStepsIsReported(): void
    {
        $yaml = self::TARGET_QA_JOB . self::EXPENSIVE_STEPS . self::TARGET_GATE_STEP;

        self::assertSame([self::GATE_AFTER_EXPENSIVE], $this->detector->check($yaml));
    }

    #[Test]
    public function aPullRequestTargetGateAheadOfTheExpensiveStepsPasses(): void
    {
        $yaml = self::TARGET_QA_JOB . self::TARGET_GATE_STEP . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aPullRequestTargetJobThatRunsNothingExpensiveNeedsNoGate(): void
    {
        $yaml = self::TARGET_QA_JOB . "      - run: echo hello\n      - uses: actions/labeler@v5\n";

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    #[DataProvider('pullRequestEventsThatAreNotPullRequestTarget')]
    public function anotherPullRequestEventIsNotAskedForTheGate(string $trigger): void
    {
        $yaml = $trigger . "jobs:\n  qa:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    /** @return Iterator<string, array{string}> */
    public static function pullRequestEventsThatAreNotPullRequestTarget(): Iterator
    {
        yield 'pull_request_review' => ["on:\n  pull_request_review:\n"];

        yield 'pull_request_review_comment' => ["on: [pull_request_review_comment]\n"];
    }

    #[Test]
    public function aWorkflowOnBothEventsKeepsThePullRequestRuleAlone(): void
    {
        $yaml = "on: [pull_request, pull_request_target]\njobs:\n  qa:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['runs on pull_request but has no up-to-date-with-base gate: add a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"` is false, with BASE_REF set from github.event.pull_request.base.ref'],
            $this->detector->check($yaml),
        );
    }
}
