<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a workflow this repository runs or ships holds more of the token than it
 * uses".
 *
 * A workflow's token is the first thing a hostile pull request, a compromised action or an
 * injected expression reaches. Each workflow here, and each template a consumer copies, therefore
 * declares its token at the top as read-only (or empty) and grants a write scope only to the job
 * that needs it. Which job may write which scope is listed below; a write the list does not name
 * fails, so a new write is a decision recorded in this file, not a line nobody noticed.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowPermissionsAreLeastPrivilegeTest extends TestCase
{
    /** The workflows this repository runs, and the ones it ships. */
    private const array WORKFLOW_DIRS = [
        __DIR__ . '/../../.github/workflows',
        __DIR__ . '/../../templates/github-actions',
    ];

    /**
     * Each file to the jobs allowed a write scope, and the scopes each may write.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array ALLOWED_WRITES = [
        'qa.yml'          => ['coverage-report' => ['pull-requests']],
        'php-qa-ci.yml'   => ['coverage-report' => ['pull-requests']],
        'qa-autofix.yml'  => ['autofix' => ['contents']],
        'release.yml'     => ['release' => ['actions', 'contents', 'pull-requests']],
        'update-deps.yml' => [
            'open-pull-request' => ['contents', 'pull-requests'],
            'approve-ci'        => ['actions'],
            'report-failure'    => ['issues'],
        ],
    ];

    public function testEveryWorkflowGrantsOnlyWhatItsJobsNeed(): void
    {
        $problems = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->problems(WorkflowNode::ofFile($file), basename($file)) as $problem) {
                $problems[] = basename(\dirname($file)) . '/' . basename($file) . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    #[Test]
    public function theGuardReportsEachWayAWorkflowHoldsTooMuchOfTheToken(): void
    {
        $allowed = ['w.yml' => ['deploy' => ['contents']]];

        self::assertSame(
            ['declares no top-level permissions, so the token takes the repository default'],
            $this->problems(WorkflowNode::ofYaml("jobs:\n  deploy:\n    runs-on: x\n"), 'w.yml', $allowed),
        );
        self::assertSame(
            ['grants write-all at the top'],
            $this->problems(WorkflowNode::ofYaml("permissions: write-all\n"), 'w.yml', $allowed),
        );
        self::assertSame(
            ['grants contents: write at the top; a write belongs to the job that needs it'],
            $this->problems(WorkflowNode::ofYaml("permissions:\n  contents: write\n"), 'w.yml', $allowed),
        );
        self::assertSame(
            ['job build grants pull-requests: write, which is not on the list of allowed writes'],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  build:\n    permissions:\n      pull-requests: write\n"),
                'w.yml',
                $allowed,
            ),
        );
        self::assertSame(
            ['job deploy grants issues: write, which is not on the list of allowed writes'],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions:\n      contents: write\n      issues: write\n"),
                'w.yml',
                $allowed,
            ),
        );
        self::assertSame(
            ['job build grants write-all'],
            $this->problems(WorkflowNode::ofYaml("permissions: {}\njobs:\n  build:\n    permissions: write-all\n"), 'w.yml', $allowed),
        );
        self::assertSame(
            [],
            $this->problems(
                WorkflowNode::ofYaml("permissions:\n  contents: read\njobs:\n  deploy:\n    permissions:\n      contents: write\n  test:\n    runs-on: x\n"),
                'w.yml',
                $allowed,
            ),
        );
    }

    /**
     * @param array<string, array<string, list<string>>>|null $allowed the list to judge against, this class's by default
     *
     * @return list<string>
     */
    private function problems(WorkflowNode $workflow, string $file, ?array $allowed = null): array
    {
        $allowed ??= self::ALLOWED_WRITES;
        if (!$workflow->has('permissions')) {
            return ['declares no top-level permissions, so the token takes the repository default'];
        }

        $problems = [];
        $top      = $workflow->child('permissions');
        if (\in_array($top->string(), ['write-all', 'read-all'], true)) {
            return [\sprintf('grants %s at the top', $top->text())];
        }

        foreach ($top->stringMap() as $scope => $level) {
            if ('write' === $level) {
                $problems[] = \sprintf('grants %s: write at the top; a write belongs to the job that needs it', $scope);
            }
        }

        foreach ($workflow->child('jobs')->entries() as $job => $node) {
            $granted = $node->child('permissions');
            if ('write-all' === $granted->string()) {
                $problems[] = \sprintf('job %s grants write-all', $job);

                continue;
            }

            foreach ($granted->stringMap() as $scope => $level) {
                if ('write' === $level && !\in_array($scope, $allowed[$file][(string)$job] ?? [], true)) {
                    $problems[] = \sprintf('job %s grants %s: write, which is not on the list of allowed writes', $job, $scope);
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [];
        foreach (self::WORKFLOW_DIRS as $dir) {
            foreach (['yml', 'yaml'] as $extension) {
                $found = \Safe\glob($dir . '/*.' . $extension);
                self::assertContainsOnlyString($found);
                $files = [...$files, ...$found];
            }
        }

        sort($files);
        self::assertNotSame([], $files, 'No workflow files found — the directory paths are wrong.');

        return $files;
    }
}
