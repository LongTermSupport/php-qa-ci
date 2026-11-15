<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\ActionPins;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * The YAML says which actions a workflow names, at any depth and written any way; the lines say how
 * they are written. Each reference the YAML holds must be matched by a line in the pinned shape, so
 * a reference written as a flow mapping is reported whatever it names.
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

    /** What every guard workflow starts with, up to the first step. */
    private const string WORKFLOW_HEAD = "jobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n";

    /** An action the guard workflows name, up to the ref. */
    private const string SOME_ACTION = 'some/action@';

    /** A step in the pinned shape, given the commit. */
    private const string PINNED_STEP = "      - uses: some/action@%s # v1.2.3\n";

    /** A step written as a flow mapping, given the commit. */
    private const string FLOW_STEP = "      - {uses: some/action@%s}\n";

    /** A step whose script is the text under it. */
    private const string SAY_STEP = "      - name: Say\n";

    /** A line in the pinned shape inside a block scalar, given the commit. */
    private const string QUOTED_PIN = "          uses: some/action@%s # v1.2.3\n";

    /** The finding for a reference, given as `owner/repo@ref`. */
    private const string NOT_PINNED = '%s is not a commit SHA with its release beside it';

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
                $this->notPinned('actions/checkout@v4'),
                $this->notPinned('some/action@main'),
                $this->notPinned(self::SOME_ACTION . $sha),
            ],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . "      - uses: actions/checkout@v4\n"
                . "      - uses: some/action@main\n"
                . \sprintf("      - uses: some/action@%s\n", $sha)
                . \sprintf(self::PINNED_STEP, $sha)
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
                $this->notPinned('actions/checkout@v4'),
                $this->notPinned(self::SOME_ACTION . $sha),
            ],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . "      - {uses: actions/checkout@v4}\n"
                . \sprintf("      - {name: Other, uses: 'some/action@%s'}\n", $sha),
            ),
        );
    }

    /**
     * A pinned line accounts for one reference the YAML holds, not for every reference naming the
     * same commit: the same commit written a second time as a flow mapping is reported.
     */
    public function testAPinnedLineAccountsForOneReferenceOnly(): void
    {
        $sha = str_repeat('c', 40);

        self::assertSame(
            [$this->notPinned(self::SOME_ACTION . $sha)],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . \sprintf(self::PINNED_STEP, $sha)
                . \sprintf(self::FLOW_STEP, $sha),
            ),
        );
    }

    /**
     * A line in the pinned shape inside a `run:` block scalar is shell text, not a step. It must not
     * account for a reference the YAML holds: the flow mapping beside it, naming the same commit, is
     * still reported.
     */
    #[DataProvider('blockScalarIndicators')]
    public function testAPinnedShapedLineInsideARunBlockAccountsForNothing(string $indicator): void
    {
        $sha = str_repeat('d', 40);

        self::assertSame(
            [$this->notPinned(self::SOME_ACTION . $sha)],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . self::SAY_STEP
                . \sprintf("        run: %s\n", $indicator)
                . \sprintf(self::QUOTED_PIN, $sha)
                . \sprintf(self::FLOW_STEP, $sha),
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function blockScalarIndicators(): iterable
    {
        yield 'literal' => ['|'];

        yield 'folded' => ['>'];

        yield 'literal that keeps its newlines' => ['|+'];
    }

    /**
     * A folded block joins its lines into one, so a pinned-shaped line inside one is the start of a
     * longer line of the parsed text; it accounts for nothing all the same.
     */
    public function testAPinnedShapedLineInsideAFoldedRunBlockOfSeveralLinesAccountsForNothing(): void
    {
        $sha = str_repeat('f', 40);

        self::assertSame(
            [$this->notPinned(self::SOME_ACTION . $sha)],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . self::SAY_STEP
                . "        run: >\n"
                . \sprintf(self::QUOTED_PIN, $sha)
                . "          and then more text\n"
                . \sprintf(self::FLOW_STEP, $sha),
            ),
        );
    }

    /** The same text in a block scalar beside a real pinned step does not hide the step's own pin. */
    public function testARealPinnedStepStillPassesBesideARunBlockThatQuotesIt(): void
    {
        $sha = str_repeat('e', 40);

        self::assertSame(
            [],
            $this->unpinned(
                self::WORKFLOW_HEAD
                . \sprintf(self::PINNED_STEP, $sha)
                . self::SAY_STEP
                . "        run: |\n"
                . \sprintf(self::QUOTED_PIN, $sha),
            ),
        );
    }

    private function notPinned(string $reference): string
    {
        return \sprintf(self::NOT_PINNED, $reference);
    }

    /** @return list<string> */
    private function unpinned(string $workflow): array
    {
        return ActionPins::unpinnedInWorkflow($workflow);
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
