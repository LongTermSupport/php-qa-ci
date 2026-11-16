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
 * A key written twice at the top of a workflow (`jobs:`, `on:`) or a job id written twice under
 * `jobs:` is rejected by GitHub, and YAML parsers disagree on which copy wins. Judging the first
 * copy only would pass the second unread, so the workflow is reported by name instead.
 *
 * @internal
 */
#[CoversClass(WorkflowBaseGateDetector::class)]
#[UsesClass(WorkflowTriggerReader::class)]
#[UsesClass(WorkflowYamlText::class)]
#[Small]
final class WorkflowBaseGateDetectorDuplicateKeyTest extends TestCase
{
    private const string TWICE = 'more than once';

    private const string GATE_STEP = "      - env:\n          BASE_REF: \${{ github.event.pull_request.base.ref }}\n        run: git merge-base --is-ancestor \"origin/\${BASE_REF}\" \"\${HEAD_SHA}\"\n";

    private const string EXPENSIVE_STEP = "      - run: composer install\n";

    private const string GATE_JOB = "  base-gate:\n    steps:\n" . self::GATE_STEP;

    private const string GATED_JOB = "  qa:\n    needs: [base-gate]\n    steps:\n" . self::EXPENSIVE_STEP;

    private const string GATELESS_JOB = "  deploy:\n    steps:\n" . self::EXPENSIVE_STEP;

    private const string BASE_GATE_ID = '"base-gate"';

    #[Test]
    #[DataProvider('pullRequestEvents')]
    public function aSecondJobsKeyIsReportedWhateverOrderTheJobsComeIn(string $event): void
    {
        $gatedFirst   = $this->head($event) . 'jobs:' . "\n" . self::GATE_JOB . self::GATED_JOB . "jobs:\n" . self::GATELESS_JOB;
        $gatelessFirst = $this->head($event) . "jobs:\n" . self::GATELESS_JOB . "jobs:\n" . self::GATE_JOB . self::GATED_JOB;

        foreach ([$gatedFirst, $gatelessFirst] as $yaml) {
            $findings = $this->check($yaml);

            self::assertCount(1, $findings);
            self::assertStringContainsString(self::TWICE, $findings[0]);
            self::assertStringContainsString('`jobs`', $findings[0]);
        }
    }

    #[Test]
    public function aSecondQuotedJobsKeyIsReportedToo(): void
    {
        $yaml = $this->head('pull_request') . "jobs:\n" . self::GATE_JOB . self::GATED_JOB . "\"jobs\" :\n" . self::GATELESS_JOB;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::TWICE, $findings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function pullRequestEvents(): iterable
    {
        yield 'pull_request' => ['pull_request'];

        yield 'pull_request_target' => ['pull_request_target'];
    }

    #[Test]
    #[DataProvider('twoOnKeys')]
    public function aSecondOnKeyIsReportedWhicheverTriggerComesFirst(string $on): void
    {
        $findings = $this->check($on . "jobs:\n" . self::GATE_JOB . self::GATED_JOB);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::TWICE, $findings[0]);
        self::assertStringContainsString('`on`', $findings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function twoOnKeys(): iterable
    {
        yield 'pull_request first' => ["on:\n  pull_request:\non:\n  push:\n"];

        yield 'push first' => ["on:\n  push:\non:\n  pull_request:\n"];

        yield 'the second one quoted and spaced' => ["on:\n  pull_request:\n\"on\" :\n  push:\n"];
    }

    #[Test]
    public function aSecondGateJobWithTheSameIdIsReported(): void
    {
        $yaml = $this->head('pull_request') . "jobs:\n" . self::GATE_JOB . "  base-gate:\n    steps:\n      - run: echo again\n" . self::GATED_JOB;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::TWICE, $findings[0]);
        self::assertStringContainsString(self::BASE_GATE_ID, $findings[0]);
    }

    #[Test]
    public function aGatelessJobWrittenTwiceIsReportedByItsId(): void
    {
        $yaml = $this->head('pull_request') . "jobs:\n  qa:\n    steps:\n" . self::EXPENSIVE_STEP . "  qa:\n    steps:\n" . self::EXPENSIVE_STEP;

        $findings = $this->check($yaml);

        self::assertCount(1, $findings);
        self::assertStringContainsString(self::TWICE, $findings[0]);
        self::assertStringContainsString('"qa"', $findings[0]);
    }

    #[Test]
    public function aJobsKeyThatIsOnlyTextInsideARunBlockIsNotASecondKey(): void
    {
        $yaml = $this->head('pull_request') . "jobs:\n" . self::GATE_JOB . self::GATED_JOB . "      - run: |\n          jobs:\n          on:\n";

        self::assertSame([], $this->check($yaml));
    }

    #[Test]
    public function aJobsKeyThatIsOnlyACommentIsNotASecondKey(): void
    {
        $yaml = $this->head('pull_request') . "# jobs:\njobs:\n" . self::GATE_JOB . self::GATED_JOB;

        self::assertSame([], $this->check($yaml));
    }

    #[Test]
    public function aSecondJobsKeyInAWorkflowThatIsNotOnAPullRequestIsNotReported(): void
    {
        $yaml = "on:\n  push:\njobs:\n" . self::GATELESS_JOB . "jobs:\n" . self::GATELESS_JOB;

        self::assertSame([], $this->check($yaml));
    }

    #[Test]
    public function twoDifferentJobIdsAreNotADuplicate(): void
    {
        $yaml = $this->head('pull_request') . "jobs:\n" . self::GATE_JOB . self::GATED_JOB;

        self::assertSame([], $this->check($yaml));
    }

    private function head(string $event): string
    {
        return "on:\n  " . $event . ":\n";
    }

    /** @return list<string> */
    private function check(string $yaml): array
    {
        return new WorkflowBaseGateDetector()->check($yaml);
    }
}
