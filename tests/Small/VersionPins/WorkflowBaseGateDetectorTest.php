<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\WorkflowBaseGateDetector;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
