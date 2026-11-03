<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "QA spent on a pull request head that is behind its base".
 *
 * The run itself stops at a gate (detected by the versionPins lane); these
 * tests pin the piece the detector does not see: the autofix template's gate
 * condition.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class StalePullRequestRunsTest extends TestCase
{
    private const string JOBS = 'jobs';

    private const string BASE_GATE = 'base-gate';

    private const string ROOT =__DIR__ . '/../..';

    private const string AUTOFIX = self::ROOT . '/templates/github-actions/qa-autofix.yml';

    #[Test]
    public function theAutofixTemplateMakesBothLaterJobsWaitForTheBaseGate(): void
    {
        $jobs = WorkflowNode::ofFile(self::AUTOFIX)->child(self::JOBS);

        self::assertTrue($jobs->has(self::BASE_GATE));
        self::assertSame(self::BASE_GATE, $jobs->child('autofix', 'needs')->string());
        self::assertSame([self::BASE_GATE, 'autofix'], $jobs->child('gate', 'needs')->strings());
    }

    #[Test]
    public function theAutofixGateDoesNotRunWhenTheBaseGateFailed(): void
    {
        $condition = WorkflowNode::ofFile(self::AUTOFIX)->child(self::JOBS, 'gate', 'if')->string();

        self::assertNotNull($condition);
        self::assertStringContainsString("needs.base-gate.result != 'failure'", $condition);
    }

    #[Test]
    public function theBaseGateRunsOnPullRequestsOnly(): void
    {
        self::assertSame(
            "\${{ github.event_name == 'pull_request' }}",
            WorkflowNode::ofFile(self::AUTOFIX)->child(self::JOBS, self::BASE_GATE, 'if')->string(),
        );
    }
}
