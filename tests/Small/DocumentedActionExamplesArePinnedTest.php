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
use Symfony\Component\Yaml\Exception\ParseException;

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

    /** The end of a fence of three backticks, on a line of its own. */
    private const string CLOSE = "```\n";

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
            . self::CLOSE
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

        yield 'a yml fence' => ["```yml\n- {uses: o/r@v1}\n```\n"];

        yield 'a double-quoted key' => ["```yaml\n- \"uses\": o/r@v1\n```\n"];

        yield 'a quoted value' => ["```yaml\n- uses: 'o/r@v1'\n```\n"];

        yield 'a mapping at the root' => ["```yaml\nuses: o/r@v1\n```\n"];

        yield 'a tilde fence' => ["~~~yaml\n- uses: o/r@v1\n~~~\n"];

        yield 'a fence of four backticks around one of three' => ["````markdown\n```yaml\n- uses: o/r@v1\n```\n````\n"];

        yield 'a step after the inner fence has closed, inside the outer' => ["````markdown\n```yaml\n- name: x\n```\n- uses: o/r@v1\n````\n"];

        yield 'an upper-case info string' => ["```YAML\n- {uses: o/r@v1}\n```\n"];

        yield 'an info string with attributes' => ["```yaml{1}\n- {uses: o/r@v1}\n```\n"];

        yield 'a tilde line inside a backtick fence' => ["```text\n~~~\n- uses: o/r@v1\n```\n"];

        yield 'a line less indented than its indented fence' => ["   ```text\n- uses: o/r@v1\n   ```\n"];

        yield 'a fence of four backticks holding a step' => ["````yaml\n- uses: o/r@v1\n````\n"];

        yield 'an indented fence' => ["1. Add it:\n\n   ```yaml\n   - uses: o/r@v1\n   ```\n"];

        yield 'a fence that is never closed' => ["```yaml\n- uses: o/r@v1\n"];

        yield 'a fence with no info string, read by line' => ["```\n- uses: o/r@v1\n  bad: [\n```\n"];

        yield 'a fence of another language, read by line' => ["```text\n- uses: o/r@v1\n  bad: [\n```\n"];

        yield 'a flow mapping in a fence with no info string' => ["```\n- {uses: o/r@v1}\n```\n"];

        yield 'a flow mapping in a text fence' => ["```text\n- {uses: o/r@v1}\n```\n"];

        yield 'a flow mapping in a yaml fence nested in a markdown fence' => ["````markdown\n```yaml\n- {uses: o/r@v1}\n```\n````\n"];

        yield 'a flow mapping in a fence whose info string is {.yaml}' => ["```{.yaml}\n- {uses: o/r@v1}\n```\n"];

        yield 'a flow mapping with other keys and a nested mapping' => ["```\n- {name: x, uses: o/r@v1, with: {a: b}}\n```\n"];

        yield 'a flow mapping with a quoted key and value' => ["```\n- {\"uses\": \"o/r@v1\"}\n```\n"];

        yield 'a flow mapping with a spaced key' => ["```\n- {uses : o/r@v1}\n```\n"];

        yield 'a quoted block key in a fence with no info string' => ["```\n- \"uses\": o/r@v1\n```\n"];

        yield 'a quoted block key in a text fence' => ["```text\n- 'uses': o/r@v1\n```\n"];

        yield 'a yaml fence inside a blockquote' => ["> ```yaml\n> - uses: o/r@v1\n> ```\n"];

        yield 'a fence inside a nested blockquote' => ["> > ```text\n> > - {uses: o/r@v1}\n> > ```\n"];

        yield 'a blockquoted fence with no space after the marker' => [">```yaml\n>- uses: o/r@v1\n>```\n"];
    }

    /** A flow-form step is reported whether or not it is a commit: the pinned shape is a block line with the release beside it. */
    public function testAFlowMappingStepNamingACommitIsReportedToo(): void
    {
        $sha = str_repeat('a', 40);

        self::assertSame(
            [\sprintf(self::NOT_PINNED, 'o/r@' . $sha)],
            ActionPins::unpinnedInMarkdown(\sprintf("```\n- {uses: o/r@%s}\n```\n", $sha)),
        );
    }

    /** What reads like a flow mapping or a `uses` key but names no remote action is not a finding. */
    #[DataProvider('textThatIsNotAnAction')]
    public function testTheGuardPassesShellTextAndLocalActionsInAFence(string $page): void
    {
        self::assertSame([], ActionPins::unpinnedInMarkdown($page));
    }

    /** @return iterable<string, array{string}> */
    public static function textThatIsNotAnAction(): iterable
    {
        yield 'a grep for the key' => ["```bash\ngrep 'uses:' workflow.yml\n```\n"];

        yield 'an echo of a flow mapping with no reference' => ["```\necho \"{uses: nothing}\"\n```\n"];

        yield 'a local action in a flow mapping' => ["```\n- {uses: ./local}\n```\n"];

        yield 'a flow mapping holding a command that mentions a reference' => ["```\n- {run: echo uses o/r@v1}\n```\n"];

        yield 'a reference with no uses key in a flow mapping' => ["```\n- {name: o/r@v1}\n```\n"];

        yield 'a blockquoted shell fence' => ["> ```bash\n> grep 'uses:' workflow.yml\n> ```\n"];

        yield 'a redirect inside a fence that is not quoted' => ["```bash\n> out.txt\n```\n\nuses: o/r@v1\n"];
    }

    /** An example tagged yaml that is not YAML is a defect in the page: a reader cannot copy it. */
    public function testAnExampleTaggedYamlThatIsNotYamlIsReported(): void
    {
        $this->expectException(ParseException::class);

        ActionPins::unpinnedInMarkdown("```yaml\n- uses: o/r@v1\n  bad: [\n```\n");
    }

    /** The failure names the page, so a reader of the failing run knows which file to open. */
    public function testTheFailureForAnUnparsableExampleNamesThePage(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('docs/some-page.md');

        ActionPins::unpinnedInMarkdown("```yaml\n- uses: o/r@v1\n  bad: [\n```\n", 'docs/some-page.md');
    }

    /** A pinned example, a shell example that merely mentions `uses:` and prose outside a fence are not findings. */
    public function testTheGuardPassesAPinnedExampleAndWhatIsNotAnAction(): void
    {
        $sha  = str_repeat('a', 40);
        $page = "```yaml\n"
            . \sprintf("- uses: o/r@%s # v1.2.3\n", $sha)
            . "- {name: Local, uses: ./.github/actions/local}\n"
            . self::CLOSE
            . "\n"
            . "```bash\n"
            . "grep 'uses:' workflow.yml\n"
            . self::CLOSE
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
