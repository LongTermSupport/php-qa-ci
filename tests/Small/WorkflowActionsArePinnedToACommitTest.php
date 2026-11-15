<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\ActionPins;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Defence for the class "a workflow this repository runs or ships names an action by a tag".
 *
 * A tag is a pointer its owner can move, so whoever controls the action's repository decides what
 * runs in this repository's jobs, with this repository's token. A full commit SHA cannot move. The
 * SHA says nothing about the release it is, so the release stays beside it in a comment, which is
 * what a person reads and what a dependency updater rewrites.
 *
 * The templates under templates/github-actions are copied into consuming projects, so a tag there
 * spreads. A reference to an action in this repository (`./`) is a path, not a tag, and is exempt.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowActionsArePinnedToACommitTest extends TestCase
{
    /** The workflows this repository runs, and the ones it ships. */
    private const array WORKFLOW_DIRS = [
        __DIR__ . '/../../.github',
        __DIR__ . '/../../templates/github-actions',
    ];

    public function testEveryRemoteActionIsPinnedToACommitWithItsReleaseBeside(): void
    {
        $problems = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->unpinned(\Safe\file_get_contents($file)) as $problem) {
                $problems[] = basename(\dirname($file)) . '/' . basename($file) . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    /** The guard proven to fire on a tag, on a branch and on a SHA with no release beside it. */
    public function testTheGuardReportsATagABranchAndAShaWithNoRelease(): void
    {
        $sha = str_repeat('a', 40);

        self::assertSame(
            [
                'actions/checkout@v4 is not a commit SHA with its release beside it',
                'some/action@main is not a commit SHA with its release beside it',
                \sprintf('some/action@%s is not a commit SHA with its release beside it', $sha),
            ],
            $this->unpinned(
                "      - uses: actions/checkout@v4\n"
                . "      - uses: some/action@main\n"
                . \sprintf("      - uses: some/action@%s\n", $sha)
                . \sprintf("      - uses: some/action@%s # v1.2.3\n", $sha)
                . "      - uses: ./.github/actions/local\n",
            ),
        );
    }

    /**
     * A reference written as a flow mapping (`- {uses: owner/repo@v1}`) is read by the YAML, not by
     * the start of a line, so a tag written that way is reported, and so is a commit written that
     * way with no release beside it.
     */
    public function testTheGuardReportsAReferenceWrittenAsAFlowMapping(): void
    {
        $sha = str_repeat('b', 40);

        self::assertSame(
            [
                'actions/checkout@v4 is not a commit SHA with its release beside it',
                \sprintf('some/action@%s is not a commit SHA with its release beside it', $sha),
            ],
            $this->unpinned(
                "jobs:\n"
                . "  build:\n"
                . "    runs-on: ubuntu-latest\n"
                . "    steps:\n"
                . "      - {uses: actions/checkout@v4}\n"
                . \sprintf("      - {name: Other, uses: 'some/action@%s'}\n", $sha),
            ),
        );
    }

    /** @return list<string> */
    private function unpinned(string $workflow): array
    {
        return ActionPins::unpinnedLines($workflow);
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [];
        foreach (self::WORKFLOW_DIRS as $dir) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (\in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);
        self::assertNotSame([], $files, 'No workflow files found — the directory paths are wrong.');

        return $files;
    }
}
