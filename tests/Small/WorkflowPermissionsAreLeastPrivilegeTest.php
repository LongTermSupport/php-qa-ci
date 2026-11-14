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

    /** The key a workflow, and a job, declares its token under. */
    private const string PERMISSIONS = 'permissions';

    /**
     * The levels a scope can take.
     *
     * @var list<string>
     */
    private const array LEVELS = ['read', self::WRITE, 'none'];

    /** The level that grants a change. */
    private const string WRITE = 'write';

    /** The scope most jobs here write, and the one the guard tests grant. */
    private const string CONTENTS = 'contents';

    /** The name the guard test gives its in-memory workflow. */
    private const string GUARD_FILE = 'w.yml';

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

    public function testEveryAllowedWriteIsStillHeldByAWorkflow(): void
    {
        $workflows = [];
        foreach ($this->workflowFiles() as $file) {
            $workflows[] = ['file' => basename($file), 'workflow' => WorkflowNode::ofFile($file)];
        }

        self::assertSame([], $this->staleAllowances(self::ALLOWED_WRITES, ...$workflows));
    }

    #[Test]
    public function theGuardReportsEachWayAWorkflowHoldsTooMuchOfTheToken(): void
    {
        $allowed = [self::GUARD_FILE => ['deploy' => [self::CONTENTS]]];

        self::assertSame(
            ['declares no top-level permissions, so the token takes the repository default'],
            $this->problems(WorkflowNode::ofYaml("jobs:\n  deploy:\n    runs-on: x\n"), self::GUARD_FILE, $allowed),
        );
        self::assertSame(
            ['grants write-all at the top'],
            $this->problems(WorkflowNode::ofYaml("permissions: write-all\n"), self::GUARD_FILE, $allowed),
        );
        self::assertSame(
            ['grants contents: write at the top; a write belongs to the job that needs it'],
            $this->problems(WorkflowNode::ofYaml("permissions:\n  contents: write\n"), self::GUARD_FILE, $allowed),
        );
        self::assertSame(
            ['job build grants pull-requests: write, which is not on the list of allowed writes'],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  build:\n    permissions:\n      pull-requests: write\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
        self::assertSame(
            ['job deploy grants issues: write, which is not on the list of allowed writes'],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions:\n      contents: write\n      issues: write\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
        self::assertSame(
            ['job build grants write-all'],
            $this->problems(WorkflowNode::ofYaml("permissions: {}\njobs:\n  build:\n    permissions: write-all\n"), self::GUARD_FILE, $allowed),
        );
        self::assertSame(
            [],
            $this->problems(
                WorkflowNode::ofYaml("permissions:\n  contents: read\njobs:\n  deploy:\n    permissions:\n      contents: write\n  test:\n    runs-on: x\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
    }

    #[Test]
    public function theGuardReportsALevelGitHubDoesNotKnow(): void
    {
        $allowed = [self::GUARD_FILE => ['deploy' => [self::CONTENTS]]];

        self::assertSame(
            ['grants contents at the top with a level that is not read, write or none'],
            $this->problems(WorkflowNode::ofYaml("permissions:\n  contents: Write\n"), self::GUARD_FILE, $allowed),
        );
        self::assertSame(
            ['job deploy grants contents with a level that is not read, write or none'],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions:\n      contents: true\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
        self::assertSame(
            [],
            $this->problems(
                WorkflowNode::ofYaml("permissions:\n  contents: none\njobs:\n  deploy:\n    permissions:\n      contents: read\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
    }

    #[Test]
    public function theGuardReportsPermissionsThatAreNotAMappingOfScopes(): void
    {
        $allowed = [self::GUARD_FILE => ['deploy' => [self::CONTENTS]]];
        $top     = 'declares permissions at the top that are not a mapping of scopes';

        foreach (['Write-All', 'write', 'true', ''] as $value) {
            self::assertSame(
                [$top],
                $this->problems(WorkflowNode::ofYaml("permissions: {$value}\n"), self::GUARD_FILE, $allowed),
                "permissions: {$value}",
            );
        }

        foreach (['Write', 'write', 'true', ''] as $value) {
            self::assertSame(
                ['job deploy declares permissions that are not a mapping of scopes'],
                $this->problems(
                    WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions: {$value}\n"),
                    self::GUARD_FILE,
                    $allowed,
                ),
                "job permissions: {$value}",
            );
        }

        self::assertSame(
            [],
            $this->problems(
                WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions: read-all\n"),
                self::GUARD_FILE,
                $allowed,
            ),
        );
    }

    #[Test]
    public function theGuardReportsAnAllowedWriteNoWorkflowHoldsAnyMore(): void
    {
        $workflow = WorkflowNode::ofYaml("permissions: {}\njobs:\n  deploy:\n    permissions:\n      contents: write\n");
        $allowed  = [
            self::GUARD_FILE => ['deploy' => [self::CONTENTS, 'issues'], 'gone' => [self::CONTENTS]],
            'other.yml'      => ['x' => ['actions']],
        ];

        self::assertSame(
            [
                'w.yml lists issues: write for job deploy, which no copy of that file grants',
                'w.yml lists contents: write for job gone, which no copy of that file grants',
                'other.yml lists actions: write for job x, which no copy of that file grants',
            ],
            $this->staleAllowances($allowed, ['file' => self::GUARD_FILE, 'workflow' => $workflow]),
        );
        self::assertSame(
            [],
            $this->staleAllowances([self::GUARD_FILE => ['deploy' => [self::CONTENTS]]], ['file' => self::GUARD_FILE, 'workflow' => $workflow]),
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
        if (!$workflow->has(self::PERMISSIONS)) {
            return ['declares no top-level permissions, so the token takes the repository default'];
        }

        $problems = [];
        $top      = $workflow->child(self::PERMISSIONS);
        if (\in_array($top->string(), ['write-all', 'read-all'], true)) {
            return [\sprintf('grants %s at the top', $top->text())];
        }

        foreach ($top->entries() as $scope => $level) {
            if (!\in_array($level->string(), self::LEVELS, true)) {
                $problems[] = \sprintf('grants %s at the top with a level that is not read, write or none', $scope);
            } elseif (self::WRITE === $level->string()) {
                $problems[] = \sprintf('grants %s: write at the top; a write belongs to the job that needs it', $scope);
            }
        }

        foreach ($workflow->child('jobs')->entries() as $job => $node) {
            $granted = $node->child(self::PERMISSIONS);
            if ('write-all' === $granted->string()) {
                $problems[] = \sprintf('job %s grants write-all', $job);

                continue;
            }

            foreach ($granted->entries() as $scope => $level) {
                if (!\in_array($level->string(), self::LEVELS, true)) {
                    $problems[] = \sprintf('job %s grants %s with a level that is not read, write or none', $job, $scope);
                } elseif (self::WRITE === $level->string() &&!\in_array((string)$scope, $allowed[$file][(string)$job] ?? [], true)) {
                    $problems[] = \sprintf('job %s grants %s: write, which is not on the list of allowed writes', $job, $scope);
                }
            }
        }

        return $problems;
    }

    /**
     * The allowed writes no workflow of that file name holds: a job that is gone, or one that no
     * longer writes the scope. An allowance nothing uses is a write nobody would notice coming back.
     *
     * @param array<string, array<string, list<string>>>  $allowed
     * @param array{file: string, workflow: WorkflowNode} ...$workflows each parsed workflow with its file name
     *
     * @return list<string>
     */
    private function staleAllowances(array $allowed, array ...$workflows): array
    {
        $stale = [];
        foreach ($allowed as $file => $jobs) {
            foreach ($jobs as $job => $scopes) {
                foreach ($scopes as $scope) {
                    $held = false;
                    foreach ($workflows as $workflow) {
                        $held = $held || (
                            $file          === $workflow['file']
                            && self::WRITE === $workflow['workflow']->child('jobs', $job, self::PERMISSIONS, $scope)->string()
                        );
                    }

                    if (!$held) {
                        $stale[] = \sprintf('%s lists %s: write for job %s, which no copy of that file grants', $file, $scope, $job);
                    }
                }
            }
        }

        return $stale;
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
