<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a push that changes only documentation runs the whole pipeline", and for
 * its opposite, "a push that must run the pipeline does not".
 *
 * A workflow triggered on `push` has a `paths` filter that leaves out Markdown and `docs/`, so a
 * README fix starts no run. The same filter must keep three things in: the Markdown that changes how
 * agents and tools behave (`CLAUDE.md`, `AGENTS.md`, `.claude/`), the workflows themselves, and
 * `CHANGELOG.md`. The release workflow runs after a green push run, and the merge of a release pull
 * request changes `CHANGELOG.md` and nothing else, so a filter that dropped it would leave every
 * release unpublished with no failing check to say why.
 *
 * The filter is read the way GitHub reads it: in order, a pattern starting with `!` takes back what
 * earlier ones matched, and the last match decides.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class PushTriggersSkipDocsOnlyPushesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    private const string PATHS = 'paths';

    /** @var list<string> */
    private const array WORKFLOWS = [
        '/templates/github-actions/php-qa-ci.yml',
        '/templates/github-actions/qa-autofix.yml',
        '/.github/workflows/qa.yml',
    ];

    /** @var list<string> a change to any of these alone must not start a run */
    private const array DOCS_ONLY = ['README.md', 'notes/design.md', 'docs/guide/install.md', 'docs/images/diagram.png'];

    /** @var list<string> a change to any of these must start a run */
    private const array MUST_RUN = [
        'src/Foo.php',
        'composer.json',
        'CHANGELOG.md',
        'CLAUDE.md',
        'AGENTS.md',
        'nested/CLAUDE.md',
        'nested/AGENTS.md',
        '.claude/settings.json',
        '.github/workflows/qa.yml',
    ];

    #[Test]
    public function aPushThatChangesOnlyDocumentationStartsNoRun(): void
    {
        foreach (self::WORKFLOWS as $workflow) {
            $patterns = $this->pushPaths($workflow);

            foreach (self::DOCS_ONLY as $file) {
                self::assertFalse($this->startsRun($patterns, $file), $workflow . ' runs for a push that changes only ' . $file);
            }
        }
    }

    #[Test]
    public function aPushThatChangesCodeConfigOrTheChangelogStillStartsARun(): void
    {
        foreach (self::WORKFLOWS as $workflow) {
            $patterns = $this->pushPaths($workflow);

            foreach (self::MUST_RUN as $file) {
                self::assertTrue($this->startsRun($patterns, $file), $workflow . ' skips a push that changes ' . $file);
            }
        }
    }

    #[Test]
    public function theFilterIsReadInOrderWithTheLastMatchDeciding(): void
    {
        $patterns = ['**', '!**.md', '**/CLAUDE.md'];

        self::assertTrue($this->startsRun($patterns, 'src/Foo.php'));
        self::assertFalse($this->startsRun($patterns, 'README.md'));
        self::assertTrue($this->startsRun($patterns, 'CLAUDE.md'), 'A pattern starting **/ also matches the root.');
        self::assertTrue($this->startsRun($patterns, 'deep/er/CLAUDE.md'));
        self::assertFalse($this->startsRun(['**', '**/CLAUDE.md', '!**.md'], 'CLAUDE.md'), 'A later negation takes back an earlier match.');
        self::assertFalse($this->startsRun([], 'src/Foo.php'));
    }

    /** @return list<string> */
    private function pushPaths(string $workflow): array
    {
        $push = WorkflowNode::ofFile(self::ROOT . $workflow)->child('on', 'push');

        self::assertTrue($push->has(self::PATHS), $workflow . ' has a push trigger with no paths filter, so a documentation-only push runs the whole pipeline.');

        return $push->child(self::PATHS)->strings();
    }

    /** @param list<string> $patterns */
    private function startsRun(array $patterns, string $file): bool
    {
        $included = false;
        foreach ($patterns as $pattern) {
            $negated = str_starts_with($pattern, '!');
            $glob    = $negated ? substr($pattern, 1) : $pattern;

            if ($this->globMatchesFile($glob, $file)) {
                $included = !$negated;
            }
        }

        return $included;
    }

    /** `*` and `**` both cross a directory separator here, which is all the shipped patterns use. */
    private function globMatchesFile(string $glob, string $file): bool
    {
        if (fnmatch($glob, $file)) {
            return true;
        }

        return str_starts_with($glob, '**/') && fnmatch(substr($glob, 3), $file);
    }
}
