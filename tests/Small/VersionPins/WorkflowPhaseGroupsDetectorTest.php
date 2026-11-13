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
    private const array GROUPS = ['allCS', 'allLints', 'allStatic', 'allTests'];

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
        self::assertSame([], $this->detector->check($this->runningEach(self::GROUPS), ...self::GROUPS));
    }

    #[Test]
    public function aGateThatRunsCodingStandardsAndStaticAnalysisButNotLintsIsReported(): void
    {
        $yaml = $this->runningEach(['allCS', 'allStatic']) . $this->deferral('allTests', 'a suite needs services');

        self::assertSame(
            ['runs qa phase groups but not allLints: add a step that runs `qa -t allLints`, or say why it is left out with a comment line `# qa-phase-deferred: allLints - <reason>`'],
            $this->detector->check($yaml, ...self::GROUPS),
        );
    }

    #[Test]
    public function everyOmittedGroupIsReportedOnItsOwn(): void
    {
        $problems = $this->detector->check($this->runningEach(['allCS']), ...self::GROUPS);

        self::assertCount(3, $problems);
        self::assertStringContainsString('allLints', $problems[0]);
        self::assertStringContainsString('allStatic', $problems[1]);
        self::assertStringContainsString('allTests', $problems[2]);
    }

    #[Test]
    public function aGroupLeftOutWithAReasonPasses(): void
    {
        $yaml = $this->runningEach(['allCS', 'allLints', 'allStatic']) . $this->deferral('allTests', 'a suite usually needs services not on the runner');

        self::assertSame([], $this->detector->check($yaml, ...self::GROUPS));
    }

    #[Test]
    public function aGroupLeftOutWithoutAReasonIsReported(): void
    {
        $yaml = $this->runningEach(['allCS', 'allLints', 'allStatic']) . "# qa-phase-deferred: allTests\n# qa-phase-deferred: allTests -   \n";

        self::assertSame(
            ['runs qa phase groups but not allTests: add a step that runs `qa -t allTests`, or say why it is left out with a comment line `# qa-phase-deferred: allTests - <reason>`'],
            $this->detector->check($yaml, ...self::GROUPS),
        );
    }

    #[Test]
    public function aCommentThatMentionsAGroupDoesNotRunIt(): void
    {
        $yaml = $this->runningEach(['allCS', 'allStatic']) . "# - The gate runs `qa -t allLints` and `qa -t allTests`.\n";

        $problems = $this->detector->check($yaml, ...self::GROUPS);

        self::assertCount(2, $problems);
        self::assertStringContainsString('allLints', $problems[0]);
        self::assertStringContainsString('allTests', $problems[1]);
    }

    #[Test]
    public function aGroupWhoseNameOnlyStartsWithAnotherIsNotThatGroup(): void
    {
        $yaml = $this->runningEach(['allCS', 'allStatic']) . "      - run: bin/qa -t allLintsAndMore\n";

        self::assertStringContainsString('allLints', $this->detector->check($yaml, ...self::GROUPS)[0]);
    }

    #[Test]
    public function aQuotedAndAnUnquotedGroupAreBothRecognised(): void
    {
        $yaml = "      - run: '\"\$(composer config bin-dir)/qa\" -t allCS'\n      - run: bin/qa -t \"allStatic\"\n";

        $problems = $this->detector->check($yaml, 'allCS', 'allStatic');

        self::assertSame([], $problems);
    }

    #[Test]
    public function aDeferralReasonMayFollowAnyOfTheAcceptedSeparators(): void
    {
        foreach ([' - ', ' -- ', ': ', ' — '] as $separator) {
            $yaml = $this->runningEach(['allCS']) . '# qa-phase-deferred: allStatic' . $separator . "slow\n";

            self::assertCount(1, $this->detector->check($yaml, 'allCS', 'allStatic', 'allLints'), 'separator "' . $separator . '"');
        }
    }

    #[Test]
    public function noGroupsToRequireMeansNothingToReport(): void
    {
        self::assertSame([], $this->detector->check($this->runningEach(['allCS'])));
    }

    /** @param list<string> $groups */
    private function runningEach(array $groups): string
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
