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
    private const string FENCE = '/^\s*```/';

    public function testEveryActionExampleInTheDocumentationIsPinnedToACommitWithItsReleaseBeside(): void
    {
        $problems = [];
        foreach ($this->markdownFiles() as $file) {
            foreach (ActionPins::unpinnedLines($this->fencedText(\Safe\file_get_contents($file))) as $problem) {
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
            ActionPins::unpinnedLines($this->fencedText($page)),
        );
    }

    /** The lines inside fenced code blocks, and nothing else. */
    private function fencedText(string $markdown): string
    {
        $inside = false;
        $kept   = [];
        foreach (explode("\n", $markdown) as $line) {
            if (1 === \Safe\preg_match(self::FENCE, $line)) {
                $inside = !$inside;

                continue;
            }

            if ($inside) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
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
