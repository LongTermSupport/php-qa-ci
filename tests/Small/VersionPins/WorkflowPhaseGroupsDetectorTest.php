<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\WorkflowPhaseGroupsDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowPhaseGroupsDetector::class)]
#[Small]
final class WorkflowPhaseGroupsDetectorTest extends TestCase
{
    private const string CS = 'allCS';

    private const string LINTS = 'allLints';

    private const string STATIC = 'allStatic';

    private const string TESTS = 'allTests';

    private const array GROUPS = [self::CS, self::LINTS, self::STATIC, self::TESTS];

    private WorkflowPhaseGroupsDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new WorkflowPhaseGroupsDetector();
    }

    #[Test]
    public function aWorkflowThatRunsNoPhaseGroupSelectsNothingToComplete(): void
    {
        $yaml = "jobs:\n  qa:\n    steps:\n      - run: '\"\$(composer config bin-dir)/qa\"'\n      - run: bin/qa -t phpstan\n";

        self::assertSame([], $this->detector->check($yaml, ...self::GROUPS));
    }

    #[Test]
    public function aWorkflowThatRunsEveryPhaseGroupPasses(): void
    {
        self::assertSame([], $this->detector->check($this->runningEach(...self::GROUPS), ...self::GROUPS));
    }

    #[Test]
    public function aGateThatRunsCodingStandardsAndStaticAnalysisButNotLintsIsReported(): void
    {
        $yaml = $this->runningEach(self::CS, self::STATIC) . $this->deferral(self::TESTS, 'a suite needs services');

        self::assertSame(
            ['runs qa phase groups but not allLints: add a step that runs `qa -t allLints`, or say why it is left out with a comment line `# qa-phase-deferred: allLints - <reason>`'],
            $this->detector->check($yaml, ...self::GROUPS),
        );
    }

    #[Test]
    public function everyOmittedGroupIsReportedOnItsOwn(): void
    {
        $problems = $this->detector->check($this->runningEach(self::CS), ...self::GROUPS);

        self::assertCount(3, $problems);
        self::assertStringContainsString(self::LINTS, $problems[0]);
        self::assertStringContainsString(self::STATIC, $problems[1]);
        self::assertStringContainsString(self::TESTS, $problems[2]);
    }

    #[Test]
    public function aGroupLeftOutWithAReasonPasses(): void
    {
        $yaml = $this->runningEach(self::CS, self::LINTS, self::STATIC) . $this->deferral(self::TESTS, 'a suite usually needs services not on the runner');

        self::assertSame([], $this->detector->check($yaml, ...self::GROUPS));
    }

    #[Test]
    public function aGroupLeftOutWithoutAReasonIsReported(): void
    {
        $yaml = $this->runningEach(self::CS, self::LINTS, self::STATIC) . "# qa-phase-deferred: allTests\n# qa-phase-deferred: allTests -   \n";

        self::assertSame(
            ['runs qa phase groups but not allTests: add a step that runs `qa -t allTests`, or say why it is left out with a comment line `# qa-phase-deferred: allTests - <reason>`'],
            $this->detector->check($yaml, ...self::GROUPS),
        );
    }

    #[Test]
    public function aCommentThatMentionsAGroupDoesNotRunIt(): void
    {
        $yaml = $this->runningEach(self::CS, self::STATIC) . "# - The gate runs `qa -t allLints` and `qa -t allTests`.\n";

        $problems = $this->detector->check($yaml, ...self::GROUPS);

        self::assertCount(2, $problems);
        self::assertStringContainsString(self::LINTS, $problems[0]);
        self::assertStringContainsString(self::TESTS, $problems[1]);
    }

    #[Test]
    public function aGroupWhoseNameOnlyStartsWithAnotherIsNotThatGroup(): void
    {
        $yaml = $this->runningEach(self::CS, self::STATIC) . "      - run: bin/qa -t allLintsAndMore\n";

        self::assertStringContainsString(self::LINTS, $this->detector->check($yaml, ...self::GROUPS)[0]);
    }

    #[Test]
    public function aQuotedAndAnUnquotedGroupAreBothRecognised(): void
    {
        $yaml = "      - run: '\"\$(composer config bin-dir)/qa\" -t allCS'\n      - run: bin/qa -t \"allStatic\"\n";

        self::assertSame([], $this->detector->check($yaml, self::CS, self::STATIC));
    }

    #[Test]
    public function twoGroupsOnOneLineAreBothRecognised(): void
    {
        $yaml = "      - run: bin/qa -t allCS && bin/qa -t allLints\n";

        self::assertSame([], $this->detector->check($yaml, self::CS, self::LINTS));
    }

    #[Test]
    public function aDeferralReasonMayFollowAnyOfTheAcceptedSeparators(): void
    {
        foreach ([' - ', ' -- ', ': ', ' — '] as $separator) {
            $yaml = $this->runningEach(self::CS) . '# qa-phase-deferred: allStatic' . $separator . "slow\n";

            self::assertCount(1, $this->detector->check($yaml, self::CS, self::STATIC, self::LINTS), 'separator "' . $separator . '"');
        }
    }

    #[Test]
    public function noGroupsToRequireMeansNothingToReport(): void
    {
        self::assertSame([], $this->detector->check($this->runningEach(self::CS)));
    }

    private function runningEach(string ...$groups): string
    {
        $yaml = "jobs:\n  gate:\n    steps:\n";
        foreach ($groups as $group) {
            $yaml .= '      - run: \'"$(composer config bin-dir)/qa" -t ' . $group . "'\n";
        }

        return $yaml;
    }

    private function deferral(string $group, string $reason): string
    {
        return '# qa-phase-deferred: ' . $group . ' - ' . $reason . "\n";
    }
}
