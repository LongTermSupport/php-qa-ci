<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowFiles;
use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a second workflow that runs the pipeline on the same push, or a
 * consumer of the pipeline run that names a workflow that does not run".
 *
 * This repository runs the pipeline template it ships (`.github/workflows/qa.yml`) and nothing
 * else: a second pipeline workflow doubles every pull request push, and the release workflow, the
 * dependency update and branch protection each locate "the CI run" by a name or a file that
 * silently stops matching if that run moves. Each must find the one workflow that exists.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class OneWorkflowGatesThisRepositoryTest extends TestCase
{
    private const string WORKFLOWS = __DIR__ . '/../../.github/workflows';

    private const string PIPELINE = self::WORKFLOWS . '/qa.yml';

    private const string RELEASE = self::WORKFLOWS . '/release.yml';

    private const string PULL_REQUEST = 'pull_request';

    private const string PUSH = 'push';

    #[Test]
    public function noSecondWorkflowRunsThePipelineOnAPullRequest(): void
    {
        $runners = [];
        foreach ($this->workflowFiles() as $file) {
            if (WorkflowNode::ofFile($file)->child('on')->has(self::PULL_REQUEST)) {
                $runners[] = basename($file);
            }
        }

        self::assertSame(['qa.yml'], $runners, 'Exactly one workflow starts on a pull request; a second one runs the pipeline twice.');
        self::assertFileDoesNotExist(self::WORKFLOWS . '/ci.yml');
    }

    #[Test]
    public function noSecondWorkflowRunsOnAPush(): void
    {
        $runners = [];
        foreach ($this->workflowFiles() as $file) {
            if ($this->startsOn(WorkflowNode::ofFile($file), self::PUSH)) {
                $runners[] = basename($file);
            }
        }

        self::assertSame(['qa.yml'], $runners, 'Exactly one workflow starts on a push; a push to a branch with an open pull request raises both events, so a second one runs twice.');
    }

    #[Test]
    public function theGuardSeesAWorkflowThatStartsOnAnEventInEveryShapeTheTriggerTakes(): void
    {
        self::assertTrue($this->startsOn(WorkflowNode::ofYaml("on:\n  push:\n    branches: [main]\n"), self::PUSH));
        self::assertTrue($this->startsOn(WorkflowNode::ofYaml("on:\n  pull_request:\n  push:\n"), self::PUSH));
        self::assertTrue($this->startsOn(WorkflowNode::ofYaml("on: [push, workflow_dispatch]\n"), self::PUSH));
        self::assertTrue($this->startsOn(WorkflowNode::ofYaml("on: push\n"), self::PUSH));
        self::assertFalse($this->startsOn(WorkflowNode::ofYaml("on:\n  pull_request:\n  schedule:\n    - cron: '1 1 * * *'\n"), self::PUSH));
        self::assertFalse($this->startsOn(WorkflowNode::ofYaml("on: [pull_request]\n"), self::PUSH));
        self::assertFalse($this->startsOn(WorkflowNode::ofYaml("name: no trigger here\n"), self::PUSH));
    }

    #[Test]
    public function everyWorkflowFileAnApprovalNamesExistsAndRunsOnAPullRequest(): void
    {
        $named = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->approvedWorkflows(WorkflowNode::ofFile($file)) as $workflow) {
                $named[basename($file)][] = $workflow;
            }
        }

        self::assertArrayHasKey('update-deps.yml', $named, 'The dependency update approves the held run of its pull request.');

        foreach ($named as $caller => $workflows) {
            foreach ($workflows as $workflow) {
                self::assertFileExists(self::WORKFLOWS . '/' . $workflow, $caller . ' approves a workflow file that does not exist.');
                self::assertTrue(
                    WorkflowNode::ofFile(self::WORKFLOWS . '/' . $workflow)->child('on')->has(self::PULL_REQUEST),
                    $caller . ' approves the held pull_request run of ' . $workflow . ', which has none.',
                );
            }
        }
    }

    /**
     * The release workflow acts on the completion of a workflow it names, and only once the run of
     * every named workflow on that commit is green; a name that matches no workflow here would
     * release on nothing, or on whatever else still carries a listed name.
     */
    #[Test]
    public function theReleaseWorkflowIsStartedAndGatedByThePipelineWorkflow(): void
    {
        $pipelineName = WorkflowNode::ofFile(self::PIPELINE)->child('name')->string();
        self::assertNotNull($pipelineName);

        $release = WorkflowNode::ofFile(self::RELEASE);
        self::assertContains($pipelineName, $release->child('on', 'workflow_run', 'workflows')->strings());

        $gating = \Safe\json_decode($release->child('jobs', 'release', 'env', 'GATING_WORKFLOWS')->text(), true);
        self::assertIsArray($gating);
        self::assertContains($pipelineName, $gating);
    }

    /** Whether `on:` names the event, as a mapping key, as an item of a list or as the one scalar. */
    private function startsOn(WorkflowNode $workflow, string $event): bool
    {
        $on = $workflow->child('on');

        return $on->has($event) || \in_array($event, $on->strings(), true) || $event === $on->string();
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        return WorkflowFiles::in(self::WORKFLOWS);
    }

    /**
     * The file names a `uses: ./.github/actions/approve-held-ci` step passes as its literal
     * `workflow:` input; one given as an expression follows the event and is not a name here.
     *
     * @return list<string>
     */
    private function approvedWorkflows(WorkflowNode $node): array
    {
        $found = [];
        if (str_ends_with($node->child('uses')->text(), '/approve-held-ci')) {
            $workflow = $node->child('with', 'workflow')->text();
            if ('' !== $workflow && !str_contains($workflow, '${{')) {
                $found[] = $workflow;
            }
        }

        foreach ($node->entries() as $child) {
            $found = [...$found, ...$this->approvedWorkflows($child)];
        }

        return $found;
    }
}
