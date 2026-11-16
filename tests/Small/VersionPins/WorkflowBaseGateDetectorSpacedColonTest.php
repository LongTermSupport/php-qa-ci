<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\WorkflowBaseGateDetector;
use LTS\PHPQA\VersionPins\WorkflowTriggerReader;
use LTS\PHPQA\VersionPins\WorkflowYamlText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A key written with spaces before its colon (`jobs :`, `qa :`, `on :`, `needs :`) is valid YAML and
 * the same key, so the workflow is judged by the real rule: not reported as unreadable, and not
 * passed unjudged either.
 *
 * @internal
 */
#[CoversClass(WorkflowBaseGateDetector::class)]
#[UsesClass(WorkflowTriggerReader::class)]
#[UsesClass(WorkflowYamlText::class)]
#[Small]
final class WorkflowBaseGateDetectorSpacedColonTest extends TestCase
{
    private const string NO_GATE = 'has no up-to-date-with-base gate';

    private const string NOT_DEPENDING = 'without depending on the up-to-date-with-base gate';

    private const string UNREADABLE_JOBS = 'its `jobs` key';

    private const string PLAIN_ON = 'on:';

    private const string PLAIN_JOBS = 'jobs:';

    private const string PLAIN_JOB_ID = 'qa:';

    private const string SEVERAL_SPACES = 'several spaces';

    private const string GATE_HEAD = "on:\n  pull_request:\njobs:\n  base-gate:\n";

    private const string GATE_STEP = "      - env:\n          BASE_REF: \${{ github.event.pull_request.base.ref }}\n        run: git merge-base --is-ancestor \"origin/\${BASE_REF}\" \"\${HEAD_SHA}\"\n";

    private const string EXPENSIVE_STEP = "      - run: composer install\n";

    private const string STEPS_OF_QA = "    steps:\n" . self::EXPENSIVE_STEP;

    private const string GATE_JOB_BODY = "    steps:\n" . self::GATE_STEP;

    #[Test]
    #[DataProvider('spacedKeys')]
    public function aGatelessJobIsReportedWhateverSpaceSitsBeforeEachColon(string $on, string $jobs, string $job): void
    {
        $yaml = $on . "\n  pull_request:\n" . $jobs . "\n  " . $job . "\n" . self::STEPS_OF_QA;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::NO_GATE, $findings[0]);
    }

    #[Test]
    #[DataProvider('spacedKeys')]
    public function aGatedJobPassesWhateverSpaceSitsBeforeEachColon(string $on, string $jobs, string $job): void
    {
        $yaml = $on . "\n  pull_request:\n" . $jobs . "\n  " . $job . "\n" . self::GATE_JOB_BODY . self::EXPENSIVE_STEP;

        self::assertSame([], $this->check($yaml));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function spacedKeys(): iterable
    {
        yield 'no space anywhere' => [self::PLAIN_ON, self::PLAIN_JOBS, self::PLAIN_JOB_ID];

        yield 'the on key' => ['on :', self::PLAIN_JOBS, self::PLAIN_JOB_ID];

        yield 'the jobs key' => [self::PLAIN_ON, 'jobs :', self::PLAIN_JOB_ID];

        yield 'the job id' => [self::PLAIN_ON, self::PLAIN_JOBS, 'qa :'];

        yield self::SEVERAL_SPACES => ['on  :', 'jobs   :', 'qa  :'];

        yield 'every key quoted and spaced' => ['"on" :', '"jobs" :', '"qa" :'];

        yield 'every key single-quoted and spaced' => ["'on' :", "'jobs' :", "'qa' :"];
    }

    #[Test]
    #[DataProvider('spacedTriggers')]
    public function aTriggerWithASpaceBeforeTheOnColonIsRead(string $trigger): void
    {
        $yaml = $trigger . "jobs:\n  qa:\n" . self::STEPS_OF_QA;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::NO_GATE, $findings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function spacedTriggers(): iterable
    {
        yield 'a scalar' => ["on : pull_request\n"];

        yield 'a flow list' => ["on : [push, pull_request]\n"];

        yield 'a block list' => ["on :\n  - pull_request\n"];

        yield 'a mapping' => ["on :\n  pull_request:\n"];

        yield 'a mapping whose event key is spaced too' => ["on :\n  pull_request :\n"];
    }

    #[Test]
    #[DataProvider('spacedNeeds')]
    public function aJobThatListsTheGateUnderASpacedNeedsKeyDependsOnIt(string $needs): void
    {
        $yaml = self::GATE_HEAD . self::GATE_JOB_BODY . "  qa:\n" . $needs . self::STEPS_OF_QA;

        self::assertSame([], $this->check($yaml));
    }

    /** @return iterable<string, array{string}> */
    public static function spacedNeeds(): iterable
    {
        yield 'a scalar' => ["    needs : base-gate\n"];

        yield 'a flow list' => ["    needs : [base-gate]\n"];

        yield 'a block list' => ["    needs :\n      - base-gate\n"];

        yield self::SEVERAL_SPACES => ["    needs   : base-gate\n"];
    }

    #[Test]
    public function aJobThatDoesNotListTheGateIsStillReportedWithASpacedNeedsKey(): void
    {
        $yaml = self::GATE_HEAD . self::GATE_JOB_BODY . "  qa:\n    needs : [other]\n" . self::STEPS_OF_QA;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::NOT_DEPENDING, $findings[0]);
    }

    #[Test]
    #[DataProvider('spacedUses')]
    public function aReusableWorkflowCallWithASpacedUsesKeyIsAnExpensiveJob(string $uses): void
    {
        $yaml = self::GATE_HEAD . self::GATE_JOB_BODY . "  call:\n" . $uses;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::NOT_DEPENDING, $findings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function spacedUses(): iterable
    {
        yield 'a space' => ["    uses : ./.github/workflows/qa.yml\n"];

        yield self::SEVERAL_SPACES => ["    uses   : ./.github/workflows/qa.yml\n"];
    }

    #[Test]
    public function aReusableWorkflowCallThatNeedsTheGateThroughASpacedKeysPasses(): void
    {
        $yaml = self::GATE_HEAD . self::GATE_JOB_BODY . "  call:\n    needs : [base-gate]\n    uses : ./.github/workflows/qa.yml\n";

        self::assertSame([], $this->check($yaml));
    }

    #[Test]
    public function aSpacedStepsAndIfKeyInTheGateJobAreRead(): void
    {
        $yaml = "on:\n  pull_request:\njobs:\n  base-gate :\n    if : github.event_name == 'pull_request'\n    steps :\n" . self::GATE_STEP . "  qa :\n    needs : [base-gate]\n    steps :\n" . self::EXPENSIVE_STEP;

        self::assertSame([], $this->check($yaml));
    }

    #[Test]
    #[DataProvider('tabBeforeTheColon')]
    public function aTabBeforeAColonIsNotValidYamlAndStaysUnreadable(string $jobs): void
    {
        $findings = $this->check("on:\n  pull_request:\n" . $jobs . self::STEPS_OF_QA);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::UNREADABLE_JOBS, $findings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function tabBeforeTheColon(): iterable
    {
        yield 'the jobs key' => ["jobs\t:\n  qa:\n"];

        yield 'the job id' => ["jobs:\n  qa\t:\n"];
    }

    /** @return list<string> */
    private function check(string $yaml): array
    {
        return (new WorkflowBaseGateDetector())->check($yaml);
    }
}
