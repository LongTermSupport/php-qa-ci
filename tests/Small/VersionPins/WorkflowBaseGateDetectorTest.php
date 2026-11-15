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

    private const string JOBS_QA = "jobs:\n  qa:\n    steps:\n";

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

    private const string TARGET_GATE_NEVER_RUNS = "its up-to-date-with-base gate is conditioned on `github.event_name == 'pull_request'`, which is false on pull_request_target, so the gate never runs: drop the condition or name pull_request_target in it";

    private const string PULL_REQUEST_NO_GATE = 'runs on pull_request but has no up-to-date-with-base gate: add a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"` is false, with BASE_REF set from github.event.pull_request.base.ref';

    private const string UNREADABLE_TRIGGER = 'its `on` trigger is written in a shape this check cannot read, so whether it runs on pull_request or pull_request_target is unknown: write it as a scalar (`on: pull_request`), a list (`on: [push, pull_request]` or a block list) or a mapping (`on:` then `  pull_request:`), without an anchor, an alias or a merge key';

    private const string JOBS_HEAD = self::JOBS_QA;

    private const string OTHER_JOB = "  other:\n    steps:\n";

    private const string CHEAP_PULL_REQUEST_ONLY_JOB = "  tidy:\n    if: github.event_name == 'pull_request'\n    steps:\n      - run: echo hello\n";

    private const string GATE_AFTER_EXPENSIVE ='job "qa" runs its gate after its first expensive step; move the gate ahead of PHP setup and composer install';

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

        self::assertSame([self::PULL_REQUEST_NO_GATE], $this->detector->check($yaml));
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
            . self::OTHER_JOB . self::EXPENSIVE_STEPS;

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
    public function thePullRequestAndPullRequestTargetTriggersAreToldApartByExactName(): void
    {
        $target = "on:\n  pull_request_target:\njobs:\n";
        $plain  = "on:\n  pull_request:\njobs:\n";

        self::assertTrue($this->detector->runsOnPullRequestTarget($target));
        self::assertFalse($this->detector->runsOnPullRequest($target));
        self::assertTrue($this->detector->runsOnPullRequest($plain));
        self::assertFalse($this->detector->runsOnPullRequestTarget($plain));
        self::assertFalse($this->detector->runsOnPullRequestTarget("on:\n  pull_request_review:\njobs:\n"));
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
        $yaml = $trigger . self::JOBS_QA . self::EXPENSIVE_STEPS;

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
    public function oneExpensiveJobAmongCheapOnesIsEnoughToAskAPullRequestTargetWorkflowForTheGate(): void
    {
        $yaml = self::TARGET_QA_JOB . "      - run: echo hello\n  other:\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([self::TARGET_NO_GATE], $this->detector->check($yaml));
    }

    #[Test]
    public function aWorkflowOnBothEventsIsAlsoToldWhenItsGateNeverRunsOnPullRequestTarget(): void
    {
        $yaml = "on: [pull_request, pull_request_target]\njobs:\n  qa:\n    steps:\n" . self::GATE_STEP . self::EXPENSIVE_STEPS;

        self::assertSame([self::TARGET_GATE_NEVER_RUNS], $this->detector->check($yaml));
    }

    #[Test]
    public function aWorkflowOnBothEventsNamesPullRequestWhenAJobDoesNotNeedTheGate(): void
    {
        $yaml = "on: [pull_request, pull_request_target]\njobs:\n  base-gate:\n    steps:\n" . self::TARGET_GATE_STEP
            . self::OTHER_JOB . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['job "other" runs on pull_request without depending on the up-to-date-with-base gate; add the gate job (base-gate) to its needs'],
            $this->detector->check($yaml),
        );
    }

    #[Test]
    public function onlyAGateJobConditionedOnThePullRequestEventIsToldItNeverRuns(): void
    {
        $yaml = self::TARGET_GATE_JOB . self::TARGET_GATE_STEP . self::CHEAP_PULL_REQUEST_ONLY_JOB;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    #[DataProvider('expensiveStepsOnTheirOwn')]
    public function anyOneExpensiveStepIsEnoughToAskAPullRequestTargetWorkflowForTheGate(string $step): void
    {
        self::assertSame([self::TARGET_NO_GATE], $this->detector->check(self::TARGET_QA_JOB . $step));
    }

    /** @return Iterator<string, array{string}> */
    public static function expensiveStepsOnTheirOwn(): Iterator
    {
        yield 'PHP setup' => ["      - uses: shivammathur/setup-php@v2\n"];

        yield 'composer install' => ["      - run: composer install\n"];

        yield 'a qa run with arguments' => ["      - run: vendor/bin/qa -t allCS\n"];

        yield 'a quoted qa run' => ["      - run: \"vendor/bin/qa\"\n"];
    }

    #[Test]
    public function aGateThatNamesPullRequestTargetInItsConditionRunsOnIt(): void
    {
        $step = str_replace(
            "if: github.event_name == 'pull_request'",
            "if: github.event_name == 'pull_request' || github.event_name == 'pull_request_target'",
            self::GATE_STEP,
        );

        self::assertSame([], $this->detector->check(self::TARGET_QA_JOB . $step . self::EXPENSIVE_STEPS));
    }

    #[Test]
    public function aPullRequestTargetExpensiveJobThatDoesNotNeedTheGateIsReported(): void
    {
        $yaml = self::TARGET_GATE_JOB . self::TARGET_GATE_STEP
            . self::OTHER_JOB . self::EXPENSIVE_STEPS;

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
        $yaml = $trigger . self::JOBS_QA . self::EXPENSIVE_STEPS;

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

        self::assertSame([self::PULL_REQUEST_NO_GATE], $this->detector->check($yaml));
    }

    /** Each way the trigger can be written is read, for both events; the finding is the one for the event. */
    #[Test]
    #[DataProvider('triggerShapesForBothEvents')]
    public function aTriggerWrittenInAnyValidShapeIsAskedForTheGate(string $event, string $trigger): void
    {
        $yaml = str_replace('%E%', $event, $trigger) . self::JOBS_HEAD . self::EXPENSIVE_STEPS;

        self::assertSame([$this->noGateFor($event)], $this->detector->check($yaml));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function triggerShapesForBothEvents(): Iterator
    {
        $shapes = [
            'a block list'                            => "on:\n  - %E%\n",
            'a block list with no indent'             => "on:\n- %E%\n",
            'a block list among others'               => "on:\n  - push\n  - %E%\n  - workflow_dispatch\n",
            'a mapping indented four spaces'          => "on:\n    %E%:\n",
            'a mapping indented six spaces'           => "on:\n      %E%:\n        types: [opened]\n",
            'a single-quoted key'                     => "on:\n  '%E%':\n",
            'a double-quoted key'                     => "on:\n  \"%E%\":\n",
            'a quoted scalar'                         => "on: '%E%'\n",
            'a double-quoted scalar'                  => "on: \"%E%\"\n",
            'a flow list over several lines'          => "on: [\n  push,\n  %E%\n]\n",
            'a flow list of quoted names'             => "on: ['push', \"%E%\"]\n",
            'a flow mapping'                          => "on: {push: {branches: [main]}, %E%: {types: [opened]}}\n",
            'a flow mapping over several lines'       => "on: {\n  push: {},\n  %E%: {}\n}\n",
            'a quoted on key'                         => "\"on\":\n  %E%:\n",
            'a single-quoted on key'                  => "'on':\n  %E%:\n",
            'a scalar followed by a comment'          => "on: %E% # runs on every pull request\n",
            'a key followed by a comment'             => "on:\n  %E%: # the event\n    types: [opened]\n",
            'a mapping after another event'           => "on:\n  push:\n    branches: [main]\n  %E%:\n    types: [opened]\n",
            'a mapping with a comment line before it' => "on:\n  # when it runs\n  %E%:\n",
        ];

        foreach (['pull_request', 'pull_request_target'] as $event) {
            foreach ($shapes as $label => $shape) {
                yield $event . ': ' . $label => [$event, $shape];
            }
        }
    }

    #[Test]
    public function aTriggerWrittenWithWindowsLineEndingsIsAskedForTheGate(): void
    {
        $yaml = str_replace("\n", "\r\n", "on:\n  pull_request:\n" . self::JOBS_HEAD . self::EXPENSIVE_STEPS);

        self::assertSame([self::PULL_REQUEST_NO_GATE], $this->detector->check($yaml));
    }

    /** What is not the event stays not the event, in any shape. */
    #[Test]
    #[DataProvider('triggersThatAreNotThePullRequestEvents')]
    public function aTriggerThatOnlyMentionsAPullRequestEventIsNotAskedForTheGate(string $trigger): void
    {
        self::assertSame([], $this->detector->check($trigger . self::JOBS_HEAD . self::EXPENSIVE_STEPS));
    }

    /** @return Iterator<string, array{string}> */
    public static function triggersThatAreNotThePullRequestEvents(): Iterator
    {
        yield 'a comment naming the target event' => ["on: [push] # pull_request_target\n"];

        yield 'a comment naming the event' => ["on:\n  push: # not pull_request\n"];

        yield 'a branch with the event name' => ["on:\n  push:\n    branches: [pull_request]\n"];

        yield 'a branch with the event name in a block list' => ["on:\n  push:\n    branches:\n      - pull_request\n      - pull_request_target\n"];

        yield 'a longer event name' => ["on:\n  - pull_request_review\n"];

        yield 'a quoted longer event name' => ["on: ['pull_request_review_comment']\n"];

        yield 'a longer event name in a mapping' => ["on:\n    pull_request_review:\n"];
    }

    /** A trigger the reader cannot reduce to event names is reported, never passed. */
    #[Test]
    #[DataProvider('triggersTheReaderCannotRead')]
    public function aTriggerThatCannotBeReadIsReportedNotPassed(string $trigger): void
    {
        self::assertSame([self::UNREADABLE_TRIGGER], $this->detector->check($trigger . self::JOBS_HEAD . self::EXPENSIVE_STEPS));
    }

    /** @return Iterator<string, array{string}> */
    public static function triggersTheReaderCannotRead(): Iterator
    {
        yield 'an alias' => ["on: *events\n"];

        yield 'an anchored scalar' => ["on: &trigger pull_request\n"];

        yield 'a merge key' => ["on:\n  <<: *base\n"];

        yield 'an alias in a block list' => ["on:\n  - *event\n"];

        yield 'no on key at all' => [''];

        yield 'an on key with nothing under it' => ["on:\n"];
    }

    /** The job, however its header is written, is found and asked for the gate. */
    #[Test]
    #[DataProvider('jobShapes')]
    public function aJobIsFoundInAnyValidShapeItsHeaderCanBeWritten(string $workflow): void
    {
        self::assertSame([self::TARGET_NO_GATE], $this->detector->check($workflow));
    }

    /** @return Iterator<string, array{string}> */
    public static function jobShapes(): Iterator
    {
        $steps = "      - run: composer install\n";

        yield 'a header followed by a comment' => [self::TARGET_TRIGGER . "  qa: # the job\n    steps:\n" . $steps];

        yield 'a single-quoted job id' => [self::TARGET_TRIGGER . "  'qa':\n    steps:\n" . $steps];

        yield 'a double-quoted job id' => [self::TARGET_TRIGGER . "  \"qa\":\n    steps:\n" . $steps];

        yield 'jobs indented four spaces' => [self::TARGET_TRIGGER . "    qa:\n      steps:\n        - run: composer install\n"];

        yield 'jobs before the trigger' => [self::JOBS_QA . $steps . "on:\n  pull_request_target:\n"];

        yield 'jobs on the first line' => [self::JOBS_QA . $steps . "\non: pull_request_target\n"];

        yield 'a quoted jobs key' => ["on:\n  pull_request_target:\n\"jobs\":\n  qa:\n    steps:\n" . $steps];

        yield 'a header with trailing spaces' => [self::TARGET_TRIGGER . "  qa:   \n    steps:\n" . $steps];

        yield 'windows line endings' => [str_replace("\n", "\r\n", self::TARGET_TRIGGER . "  qa:\n    steps:\n" . $steps)];
    }

    #[Test]
    public function aBlockListNeedsNamingTheGateJobIsRead(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  fix:\n    needs:\n      - base-gate\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aFlowNeedsListOverSeveralLinesAndQuotedNamesAreRead(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  fix:\n    needs: [\n      'base-gate',\n      other\n    ]\n    steps:\n" . self::EXPENSIVE_STEPS;

        self::assertSame([], $this->detector->check($yaml));
    }

    #[Test]
    public function aNeedsKeyDeeperDownIsNotTheJobsNeeds(): void
    {
        $yaml = self::GATE_JOB . self::GATE_STEP
            . "  fix:\n    steps:\n      - name: x\n        with:\n          needs: base-gate\n" . self::EXPENSIVE_STEPS;

        self::assertSame(
            ['job "fix" runs on pull_request without depending on the up-to-date-with-base gate; add the gate job (base-gate) to its needs'],
            $this->detector->check($yaml),
        );
    }

    /** A job that only calls a reusable workflow cannot be read from this file, so it is treated as expensive. */
    #[Test]
    #[DataProvider('reusableWorkflowCalls')]
    public function aJobThatOnlyCallsAReusableWorkflowIsAskedForTheGate(string $call): void
    {
        self::assertSame([self::TARGET_NO_GATE], $this->detector->check(self::TARGET_TRIGGER . "  call:\n" . $call));
    }

    /** @return Iterator<string, array{string}> */
    public static function reusableWorkflowCalls(): Iterator
    {
        yield 'a local workflow' => ["    uses: ./.github/workflows/qa.yml\n"];

        yield 'a remote workflow' => ["    uses: owner/repo/.github/workflows/qa.yml@main\n"];

        yield 'with inputs' => ["    uses: ./.github/workflows/qa.yml\n    with:\n      flag: true\n"];
    }

    #[Test]
    public function aStepsUsesKeyIsNotAReusableWorkflowCall(): void
    {
        self::assertSame([], $this->detector->check(self::TARGET_QA_JOB . "      - uses: actions/labeler@v5\n"));
    }

    #[Test]
    #[DataProvider('moreExpensiveSteps')]
    public function aBareQaRunAndComposerQaAreExpensiveToo(string $step): void
    {
        self::assertSame([self::TARGET_NO_GATE], $this->detector->check(self::TARGET_QA_JOB . $step));
    }

    /** @return Iterator<string, array{string}> */
    public static function moreExpensiveSteps(): Iterator
    {
        yield 'a bare qa path' => ["      - run: vendor/bin/qa\n"];

        yield 'a single-quoted qa path' => ["      - run: 'vendor/bin/qa'\n"];

        yield 'composer qa' => ["      - run: composer qa\n"];

        yield 'composer qa with arguments' => ["      - run: composer qa -- -t phpstan\n"];

        yield 'a qa run in a block' => ["      - run: |\n          echo start\n          bin/qa\n"];
    }

    #[Test]
    #[DataProvider('cheapStepsThatLookLikeQa')]
    public function aWordThatOnlyContainsQaIsNotAQaRun(string $step): void
    {
        self::assertSame([], $this->detector->check(self::TARGET_QA_JOB . $step));
    }

    /** @return Iterator<string, array{string}> */
    public static function cheapStepsThatLookLikeQa(): Iterator
    {
        yield 'a word ending in qa' => ["      - run: echo vqa\n"];

        yield 'a path containing qa as a part' => ["      - run: echo docs/qa-notes.md\n"];
    }

    /** The condition is read in whatever spacing, case or operand order GitHub accepts. */
    #[Test]
    #[DataProvider('pullRequestOnlyConditions')]
    public function aGateWhoseConditionIsThePullRequestEventInAnySpellingNeverRunsOnPullRequestTarget(string $condition): void
    {
        $step = str_replace("github.event_name == 'pull_request'", $condition, self::GATE_STEP);

        self::assertSame([self::TARGET_GATE_NEVER_RUNS], $this->detector->check(self::TARGET_QA_JOB . $step . self::EXPENSIVE_STEPS));
    }

    /** @return Iterator<string, array{string}> */
    public static function pullRequestOnlyConditions(): Iterator
    {
        yield 'no spaces' => ["github.event_name=='pull_request'"];

        yield 'more spaces' => ["github.event_name  ==   'pull_request'"];

        yield 'upper case' => ["GITHUB.EVENT_NAME == 'PULL_REQUEST'"];

        yield 'operands the other way round' => ["'pull_request' == github.event_name"];

        yield 'inside an expression' => ["\${{ github.event_name == 'pull_request' }}"];

        yield 'among other conditions' => ["success() && github.event_name == 'pull_request'"];
    }

    /** A comment is not the code: the thing a comment names does not count, in either direction. */
    #[Test]
    public function aCommentNamingTheConditionIsNotTheCondition(): void
    {
        $step = str_replace(
            "            if: github.event_name == 'pull_request'\n",
            "            # if: github.event_name == 'pull_request'\n",
            self::TARGET_GATE_STEP,
        );

        self::assertSame([], $this->detector->check(self::TARGET_QA_JOB . $step . self::EXPENSIVE_STEPS));
    }

    #[Test]
    public function aCommentNamingPullRequestTargetDoesNotExemptAGateThatNeverRuns(): void
    {
        $step = str_replace(
            "if: github.event_name == 'pull_request'",
            "if: github.event_name == 'pull_request' # also for pull_request_target",
            self::GATE_STEP,
        );

        self::assertSame([self::TARGET_GATE_NEVER_RUNS], $this->detector->check(self::TARGET_QA_JOB . $step . self::EXPENSIVE_STEPS));
    }

    #[Test]
    public function aCommentNamingAnExpensiveCommandIsNotAnExpensiveStep(): void
    {
        self::assertSame([], $this->detector->check(self::TARGET_QA_JOB . "      - run: echo hello # composer install\n      # - run: vendor/bin/qa\n"));
    }

    #[Test]
    public function aCommentCarryingTheGateTextIsNotAGate(): void
    {
        $yaml = self::QA_JOB
            . "      # git merge-base --is-ancestor origin/main HEAD with github.event.pull_request.base.ref\n"
            . self::EXPENSIVE_STEPS;

        self::assertSame([self::PULL_REQUEST_NO_GATE], $this->detector->check($yaml));
    }

    private function noGateFor(string $event): string
    {
        return 'pull_request' === $event ? self::PULL_REQUEST_NO_GATE : self::TARGET_NO_GATE;
    }
}
