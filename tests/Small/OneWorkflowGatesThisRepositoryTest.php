<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defence for the class "a second workflow that runs the pipeline on the same push, or a
 * consumer of the pipeline run that names a workflow that no longer runs".
 *
 * This repository runs the pipeline template it ships (`.github/workflows/qa.yml`) and nothing
 * else: a second pipeline workflow doubled every pull request push, and the release workflow, the
 * dependency update and branch protection each located "the CI run" by a name or a file that
 * would silently stop matching if that run moved. Each must find the one workflow that exists.
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

    private const string ON = 'on';

    private const string JOBS = 'jobs';

    #[Test]
    public function noSecondWorkflowRunsThePipelineOnAPush(): void
    {
        $runners = [];
        foreach ($this->workflowFiles() as $file) {
            $workflow = $this->parse($file);
            $triggers = $workflow[self::ON] ?? [];
            if (!\is_array($triggers) || !\array_key_exists('pull_request', $triggers)) {
                continue;
            }

            $runners[] = basename($file);
        }

        self::assertSame(['qa.yml'], $runners, 'Exactly one workflow starts on a pull request; a second one runs the pipeline twice.');
        self::assertFileDoesNotExist(self::WORKFLOWS . '/ci.yml');
    }

    #[Test]
    public function everyWorkflowFileAnApprovalNamesExistsAndRunsOnAPullRequest(): void
    {
        $named = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->approvedWorkflows($file) as $workflow) {
                $named[basename($file)][] = $workflow;
            }
        }

        self::assertArrayHasKey('update-deps.yml', $named, 'The dependency update approves the held run of its pull request.');

        foreach ($named as $caller => $workflows) {
            foreach ($workflows as $workflow) {
                self::assertFileExists(self::WORKFLOWS . '/' . $workflow, $caller . ' approves a workflow file that does not exist.');
                $triggers = $this->parse(self::WORKFLOWS . '/' . $workflow)[self::ON] ?? [];
                self::assertIsArray($triggers);
                self::assertArrayHasKey('pull_request', $triggers, $caller . ' approves the held pull_request run of ' . $workflow . ', which has none.');
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
        $pipelineName = $this->parse(self::PIPELINE)['name'] ?? null;
        self::assertIsString($pipelineName);

        $release = $this->parse(self::RELEASE);
        $started = $release[self::ON]['workflow_run']['workflows'] ?? null;
        self::assertIsArray($started);
        self::assertContains($pipelineName, $started);

        $gating = \Safe\json_decode((string)($release[self::JOBS]['release']['env']['GATING_WORKFLOWS'] ?? ''), true);
        self::assertIsArray($gating);
        self::assertContains($pipelineName, $gating);
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = \Safe\glob(self::WORKFLOWS . '/*.yml');
        self::assertContainsOnlyString($files);
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function parse(string $file): array
    {
        $workflow = Yaml::parse(\Safe\file_get_contents($file));
        self::assertIsArray($workflow);

        return $workflow;
    }

    /**
     * The file names a `uses: ./.github/actions/approve-held-ci` step passes as its literal
     * `workflow:` input; one given as an expression follows the event and is not a name here.
     *
     * @return list<string>
     */
    private function approvedWorkflows(string $file): array
    {
        $parsed = $this->parse($file);
        $found  = [];
        $this->collectApprovals($parsed, $found);

        return $found;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $found
     */
    private function collectApprovals(array $node, array &$found): void
    {
        if (str_ends_with((string)($node['uses'] ?? ''), '/approve-held-ci') && \is_string($node['with']['workflow'] ?? null) && !str_contains($node['with']['workflow'], '${{')) {
            $found[] = $node['with']['workflow'];
        }

        foreach ($node as $child) {
            if (\is_array($child)) {
                $this->collectApprovals($child, $found);
            }
        }
    }
}
