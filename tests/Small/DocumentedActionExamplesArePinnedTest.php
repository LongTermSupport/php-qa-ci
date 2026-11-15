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
 * Defence for the class "the documentation teaches the construction the pin check bans".
 *
 * {@see WorkflowActionsArePinnedToACommitTest} holds every workflow this repository runs or ships
 * to a commit, so a reader who copies an example from a page should get the same form. An example
 * that names an action by a tag is a form the shipped templates do not use.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class DocumentedActionExamplesArePinnedTest extends TestCase
{
    /** The finding for a tag, given as `owner/repo@ref`. */
    private const string NOT_PINNED = '%s is not a commit SHA with its release beside it';

    /** An example reference in a tag form, which every fixture below must have reported. */
    private const string TAG = 'o/r@v1';

    public function testEveryActionExampleInTheDocumentationIsPinnedToACommitWithItsReleaseBeside(): void
    {
        $problems = [];
        foreach ($this->markdownFiles() as $file) {
            foreach (ActionPins::unpinnedInMarkdown(\Safe\file_get_contents($file)) as $problem) {
                $problems[] = str_replace(__DIR__ . '/../../', '', $file) . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    /** The guard proven to read only what a fence holds, and to report a tag in one. */
    public function testTheGuardReportsATagInsideAFenceAndNotOneInProse(): void
    {
        $page = "Use `uses: actions/checkout@v4` as you like.\n"
            . "\n"
            . "```yaml\n"
            . "- uses: codecov/codecov-action@v3\n"
            . "```\n"
            . "\n"
            . "  uses: prose/not-a-fence@v1\n";

        self::assertSame(
            ['codecov/codecov-action@v3 is not a commit SHA with its release beside it'],
            ActionPins::unpinnedInMarkdown($page),
        );
    }

    /**
     * Every way an example can name a tag is reported: the block is read as YAML where it is YAML, so
     * the key can be quoted and the step written as a flow mapping, and the fence can be any of the
     * ways markdown writes one.
     */
    #[DataProvider('tagsInAnyExampleShape')]
    public function testTheGuardReportsATagWhicheverWayTheExampleWritesIt(string $page): void
    {
        self::assertSame([\sprintf(self::NOT_PINNED, self::TAG)], ActionPins::unpinnedInMarkdown($page));
    }

    /** @return iterable<string, array{string}> */
    public static function tagsInAnyExampleShape(): iterable
    {
        yield 'a flow mapping step' => ["```yaml\n- {uses: o/r@v1}\n```\n"];

        yield 'a quoted key' => ["```yaml\n- 'uses': o/r@v1\n```\n"];

        yield 'a double-quoted key' => ["```yaml\n- \"uses\": o/r@v1\n```\n"];

        yield 'a quoted value' => ["```yaml\n- uses: 'o/r@v1'\n```\n"];

        yield 'a mapping at the root' => ["```yaml\nuses: o/r@v1\n```\n"];

        yield 'a tilde fence' => ["~~~yaml\n- uses: o/r@v1\n~~~\n"];

        yield 'a fence of four backticks around one of three' => ["````markdown\n```yaml\n- uses: o/r@v1\n```\n````\n"];

        yield 'a fence of four backticks holding a step' => ["````yaml\n- uses: o/r@v1\n````\n"];

        yield 'an indented fence' => ["1. Add it:\n\n   ```yaml\n   - uses: o/r@v1\n   ```\n"];

        yield 'a block that is not YAML' => ["```yaml\n- uses: o/r@v1\n  bad: [\n```\n"];

        yield 'a fence that is never closed' => ["```yaml\n- uses: o/r@v1\n"];
    }

    /** A pinned example, a shell example that merely mentions `uses:` and prose outside a fence are not findings. */
    public function testTheGuardPassesAPinnedExampleAndWhatIsNotAnAction(): void
    {
        $sha  = str_repeat('a', 40);
        $page = "```yaml\n"
            . \sprintf("- uses: o/r@%s # v1.2.3\n", $sha)
            . "- {name: Local, uses: ./.github/actions/local}\n"
            . "```\n"
            . "\n"
            . "```bash\n"
            . "grep 'uses:' workflow.yml\n"
            . "```\n"
            . "\n"
            . "~~~yaml\n"
            . "- name: Say\n"
            . "  run: echo uses o/r@v1\n"
            . "~~~\n";

        self::assertSame([], ActionPins::unpinnedInMarkdown($page));
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $files = [__DIR__ . '/../../README.md'];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../docs', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('md' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
