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
 * tests pin the pieces the detector does not see: the autofix template's gate
 * condition and the workflow that cancels runs already in flight when the base
 * moves, which must stay inside the default token's reach.
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

    private const string CANCEL = self::ROOT . '/.github/workflows/cancel-stale-pr-runs.yml';

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

    #[Test]
    public function theCancelWorkflowRunsOnAPushToTheDefaultBranchOnly(): void
    {
        $on = WorkflowNode::ofFile(self::CANCEL)->child('on');

        self::assertSame(['push'], $on->keys());
        self::assertSame(['php8.5'], $on->child('push', 'branches')->strings());
    }

    #[Test]
    public function theCancelWorkflowNeedsNothingBeyondTheDefaultToken(): void
    {
        $workflow = WorkflowNode::ofFile(self::CANCEL);

        self::assertSame(
            ['actions' => 'write', 'pull-requests' => 'read', 'contents' => 'read'],
            $workflow->child('permissions')->stringMap(),
        );
        self::assertStringNotContainsString('secrets.', \Safe\file_get_contents(self::CANCEL));
    }
}
