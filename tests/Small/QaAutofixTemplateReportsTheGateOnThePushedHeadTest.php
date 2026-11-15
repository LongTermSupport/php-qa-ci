<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a pull request head that carries none of the gate's result".
 *
 * The autofix job pushes with the default token, and a push made with that token starts no
 * workflow run. The gate validates the fixed tip inside the run that pushed it, but a check
 * belongs to the commit its run started on, so the auto-fix commit shows no check and a required
 * one never arrives. The template therefore has the gate record the commit it checked out, and a
 * job of its own post a commit status for that commit under the gate's name. That job holds the
 * one scope it needs, runs none of the project's code, and receives every value as an
 * environment variable, never as text substituted into the script it runs.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class QaAutofixTemplateReportsTheGateOnThePushedHeadTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../templates/github-actions/qa-autofix.yml';

    private const string STEPS = 'steps';

    private const string RUN = 'run';

    private const string ENV = 'env';

    private const string GATE = 'gate';

    private const string STATUS_JOB = 'gate-status';

    private const string EXPRESSION = '${{';

    private const string SHA_OUTPUT = '${{ steps.tip.outputs.sha }}';

    private const string GATE_SHA = 'needs.gate.outputs.sha';

    #[Test]
    public function theGateRecordsTheCommitItCheckedOutRightAfterTheCheckout(): void
    {
        $gate  = $this->job(self::GATE);
        $steps = $gate->child(self::STEPS)->items();

        self::assertSame(self::SHA_OUTPUT, $gate->child('outputs', 'sha')->string(), 'the gate job does not publish the commit it checked out');
        self::assertStringStartsWith('actions/checkout@', $steps[0]->child('uses')->text(), 'the first step of the gate is not its checkout');
        self::assertSame('tip', $steps[1]->child('id')->string(), 'the step after the checkout is not the one that records the commit');
        self::assertStringContainsString('git rev-parse HEAD', $steps[1]->child(self::RUN)->text());
        self::assertStringContainsString('GITHUB_OUTPUT', $steps[1]->child(self::RUN)->text());
    }

    #[Test]
    public function aJobOfItsOwnPostsTheStatusAfterTheGate(): void
    {
        $job = $this->job(self::STATUS_JOB);

        self::assertSame(self::GATE, $job->child('needs')->string(), 'the status job does not wait for the gate alone');
    }

    #[Test]
    public function theStatusJobHoldsTheStatusesScopeAndNothingElse(): void
    {
        $permissions = $this->job(self::STATUS_JOB)->child('permissions');

        self::assertSame(['statuses'], $permissions->keys());
        self::assertSame('write', $permissions->child('statuses')->string());
    }

    #[Test]
    public function theStatusJobRunsNoActionAndNoProjectCode(): void
    {
        $steps = $this->job(self::STATUS_JOB)->child(self::STEPS)->items();

        self::assertNotSame([], $steps, 'the status job has no steps');
        foreach ($steps as $step) {
            self::assertFalse($step->has('uses'), 'the status job runs an action, which may check the project out or run its code');
            self::assertStringNotContainsString(self::EXPRESSION, $step->child(self::RUN)->text(), 'a value is substituted into the script; pass it as an environment variable');
        }
    }

    #[Test]
    public function theStatusIsPostedOnlyForAPullRequestFromThisRepositoryWhoseCheckedOutCommitIsNotTheOneTheRunStartedOn(): void
    {
        $condition = $this->job(self::STATUS_JOB)->child('if')->text();

        foreach ([
            'always()',
            "github.event_name == 'pull_request'",
            'github.event.pull_request.head.repo.full_name == github.repository',
            self::GATE_SHA . " != ''",
            self::GATE_SHA . ' != github.event.pull_request.head.sha',
        ] as $required) {
            self::assertStringContainsString($required, $condition);
        }
    }

    #[Test]
    public function theStatusIsPostedForTheCheckedOutCommitUnderTheGatesNameAndOnlyASuccessfulGateIsSuccess(): void
    {
        $step = $this->job(self::STATUS_JOB)->child(self::STEPS, 0);
        $run  = $step->child(self::RUN)->text();

        self::assertSame('${{ needs.gate.result }}', $step->child(self::ENV, 'GATE_RESULT')->string());
        self::assertSame('${{ ' . self::GATE_SHA . ' }}', $step->child(self::ENV, 'SHA')->string());
        self::assertSame('${{ github.token }}', $step->child(self::ENV, 'GH_TOKEN')->string());
        self::assertStringContainsString('gh api --method POST "repos/${REPOSITORY}/statuses/${SHA}"', $run);
        self::assertMatchesRegularExpression('/if \[ "\$\{GATE_RESULT\}" = "success" \]; then\n\s*STATE=success\n\s*else\n\s*STATE=failure\n\s*fi/', $run, 'only a gate whose result is success may post success');
        self::assertStringContainsString('state="${STATE}"', $run);
        self::assertStringContainsString('context="${CONTEXT}"', $run);
    }

    #[Test]
    public function theStatusContextIsTheNameTheGateJobReports(): void
    {
        $name = $this->job(self::GATE)->child('name')->string();

        self::assertNotNull($name, 'the gate job has no name');
        self::assertSame($name, $this->job(self::STATUS_JOB)->child(self::STEPS, 0, self::ENV, 'CONTEXT')->string(), 'a rename of the gate leaves the status under a name nothing requires');
    }

    private function job(string $id): WorkflowNode
    {
        return WorkflowNode::ofFile(self::TEMPLATE)->child('jobs', $id);
    }
}
