<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Markdown;

use Iterator;
use LTS\PHPQA\Markdown\DocumentSelfReferenceFinding;
use LTS\PHPQA\Markdown\DocumentSelfReferenceScanner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocumentSelfReferenceScanner::class)]
#[CoversClass(DocumentSelfReferenceFinding::class)]
#[Small]
final class DocumentSelfReferenceScannerTest extends TestCase
{
    private const string PROJECT_CLEAN = __DIR__ . '/../../assets/docsSelfReference/projectClean';

    private const string PROJECT_DIRTY = __DIR__ . '/../../assets/docsSelfReference/projectWithSelfReference';

    private const string PREVIOUS_VERSION = 'previous version of this section';

    private const string PAGE_USED_TO = 'this page used to';

    private const string PAGE_USED_TO_PROSE = 'This page used to document Bash.';

    private const string SECTION_MOVED = 'this section has been moved';

    private const string SECTION_MOVED_PROSE = 'This section has been moved.';

    private const string FILE = 'doc.md';

    private const string AT_TIME_OF_WRITING = 'at time of writing';

    private const string README = 'README.md';

    private DocumentSelfReferenceScanner $scanner;

    private ?TempDir $project = null;

    protected function setUp(): void
    {
        $this->scanner = new DocumentSelfReferenceScanner();
    }

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    /**
     * The line is where the paragraph starts, counted from 1, however many lines the
     * paragraph spans and however far down the document it sits.
     */
    #[Test]
    public function itReportsEachParagraphAtTheLineItStartsOn(): void
    {
        $markdown = "# Title\n\nSome prose.\nThis page used to\ndocument Bash.\n\n" . self::SECTION_MOVED_PROSE;

        self::assertEquals(
            [
                new DocumentSelfReferenceFinding(self::FILE, 3, self::PAGE_USED_TO, 'Some prose. ' . self::PAGE_USED_TO_PROSE),
                new DocumentSelfReferenceFinding(self::FILE, 7, self::SECTION_MOVED, self::SECTION_MOVED_PROSE),
            ],
            $this->scanner->scanText($markdown, self::FILE),
        );
    }

    /**
     * A line holding only spaces and tabs renders as a blank line, so it ends the
     * paragraph exactly as an empty one does.
     */
    #[Test]
    public function aWhitespaceOnlyLineEndsTheParagraph(): void
    {
        self::assertSame([], $this->scanner->scanText("This page\n \t \nused to document Bash.", self::FILE));
    }

    #[Test]
    public function theContextIsTheParagraphWithoutSurroundingWhitespace(): void
    {
        self::assertEquals(
            [new DocumentSelfReferenceFinding(self::FILE, 1, self::PAGE_USED_TO, self::PAGE_USED_TO_PROSE)],
            $this->scanner->scanText("   This page used to\tdocument Bash. \t ", self::FILE),
        );
    }

    #[Test]
    public function aParagraphMatchingSeveralShapesIsReportedOncePerShape(): void
    {
        $prose = 'This page used to list 17 rules at time of writing.';

        self::assertEquals(
            [
                new DocumentSelfReferenceFinding(self::FILE, 1, self::AT_TIME_OF_WRITING, $prose),
                new DocumentSelfReferenceFinding(self::FILE, 1, self::PAGE_USED_TO, $prose),
            ],
            $this->scanner->scanText($prose, self::FILE),
        );
    }

    /**
     * Both fence spellings, indented or not, blank their contents; the line count is
     * kept, so the paragraph after the block is reported at its true line.
     */
    #[Test]
    public function fencedBlocksAreBlankedWithoutShiftingLineNumbers(): void
    {
        $markdown = "```markdown\n" . self::PAGE_USED_TO_PROSE . "\n```\n\n"
            . "  ~~~\nThe previous version of this page listed rules.\n  ~~~\n\n"
            . self::SECTION_MOVED_PROSE;

        self::assertEquals(
            [new DocumentSelfReferenceFinding(self::FILE, 9, self::SECTION_MOVED, self::SECTION_MOVED_PROSE)],
            $this->scanner->scanText($markdown, self::FILE),
        );
    }

    /**
     * Only README.md and the docs tree are in scope, and the findings come in path
     * order whatever order the filesystem lists the files in: they are written here
     * in reverse, which is the order a creation-ordered directory listing returns.
     */
    #[Test]
    public function itScansOnlyTheReadmeAndTheDocsTreeInPathOrder(): void
    {
        $project = $this->project();
        $project->write('CLAUDE.md', self::SECTION_MOVED_PROSE);
        $project->write('notes/plan.md', self::SECTION_MOVED_PROSE);
        foreach (['e', 'd', 'c/b', 'a'] as $name) {
            $project->write('docs/' . $name . '.md', self::SECTION_MOVED_PROSE);
        }

        $project->write('docs/z.txt', self::SECTION_MOVED_PROSE);
        $project->write(self::README, self::PAGE_USED_TO_PROSE);

        self::assertEquals(
            [
                new DocumentSelfReferenceFinding(self::README, 1, self::PAGE_USED_TO, self::PAGE_USED_TO_PROSE),
                new DocumentSelfReferenceFinding('docs/a.md', 1, self::SECTION_MOVED, self::SECTION_MOVED_PROSE),
                new DocumentSelfReferenceFinding('docs/c/b.md', 1, self::SECTION_MOVED, self::SECTION_MOVED_PROSE),
                new DocumentSelfReferenceFinding('docs/d.md', 1, self::SECTION_MOVED, self::SECTION_MOVED_PROSE),
                new DocumentSelfReferenceFinding('docs/e.md', 1, self::SECTION_MOVED, self::SECTION_MOVED_PROSE),
            ],
            $this->scanner->scan($project->path),
        );
    }

    /**
     * A trailing slash on the root names the same project, so it must produce the
     * same findings. The docs tree here repeats the root's own path, the case where
     * the slash decides what is stripped from the middle of a file's path.
     */
    #[Test]
    public function aTrailingSlashOnTheRootChangesNothing(): void
    {
        $project = $this->project();
        $project->write('docs' . $project->path . '/x.md', self::SECTION_MOVED_PROSE);

        $withoutSlash = $this->scanner->scan($project->path);

        self::assertCount(1, $withoutSlash);
        self::assertEquals($withoutSlash, $this->scanner->scan($project->path . '/'));
    }

    #[Test]
    public function itReportsNothingForDocumentationThatDescribesTheTool(): void
    {
        $findings = $this->scanner->scan(self::PROJECT_CLEAN);

        self::assertSame([], $findings, 'prose about the subject must never be reported');
    }

    /**
     * The whole corpus is hard-wrapped, so the phrases this rule looks for routinely
     * straddle a newline. A line-oriented implementation passes a single-line fixture
     * and is blind to the real thing, which is exactly what happened to the first
     * search that motivated this rule.
     */
    #[Test]
    public function itFindsASelfReferenceThatStraddlesALineBreak(): void
    {
        $findings = $this->scanner->scan(self::PROJECT_DIRTY);
        $matched  = array_map(static fn (DocumentSelfReferenceFinding $f): string => $f->matched, $findings);

        self::assertContains('copy here was maintained by hand', $matched);
        self::assertContains(self::PREVIOUS_VERSION, $matched);
    }

    #[Test]
    public function itFindsACurrencyCaveatAboutThePageItself(): void
    {
        $findings = $this->scanner->scan(self::PROJECT_DIRTY);
        $matched  = array_map(static fn (DocumentSelfReferenceFinding $f): string => $f->matched, $findings);

        self::assertContains(self::AT_TIME_OF_WRITING, $matched);
    }

    #[Test]
    public function itReportsTheFileAndTheLineTheParagraphStartsOn(): void
    {
        $findings = $this->scanner->scan(self::PROJECT_DIRTY);

        self::assertNotSame([], $findings);
        foreach ($findings as $finding) {
            self::assertGreaterThan(0, $finding->line, 'every finding must carry a usable line number');
            self::assertStringEndsWith('.md', $finding->file);
            self::assertStringNotContainsString(self::PROJECT_DIRTY, $finding->file, 'the file must be project-relative');
        }
    }

    #[Test]
    public function itScansBothTheReadmeAndTheDocsTree(): void
    {
        $findings = $this->scanner->scan(self::PROJECT_DIRTY);
        $files    = array_unique(array_map(static fn (DocumentSelfReferenceFinding $f): string => $f->file, $findings));
        sort($files);

        self::assertSame([self::README, 'docs/tool.md'], $files);
    }

    /**
     * The bound that makes the rule usable: an upgrade guide's whole job is "previously
     * X, now Y". Narrowing to SELF-reference is what keeps those clean.
     *
     * @param non-empty-string $prose
     */
    #[Test]
    #[DataProvider('proseAboutTheSubjectProvider')]
    public function itNeverReportsProseAboutTheSubject(string $prose): void
    {
        self::assertSame([], $this->scanner->scanText($prose), \sprintf('must not report: %s', $prose));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function proseAboutTheSubjectProvider(): Iterator
    {
        yield 'migration guidance' => ['Previously the pipeline was configured in Bash; it is now PHP.'];
        yield 'renamed api' => ['`useArkitect=0` was the old spelling of `withArkitect(false)`.'];
        yield 'deprecation' => ['This option has been deprecated in favour of the builder method.'];
        yield 'codebase history' => ['Three lanes had grown this way before the tools moved to PHARs.'];
        yield 'present-tense policy' => ['This page does not list the rules; the registry is the list.'];
        yield 'incident motivation' => ['This happened to ForbidMockingFinalClassRule, whose tests failed under vendor/.'];
        // A page naming the construction it forbids is quoting, not asserting. Found by
        // running the lane: its own documentation table reported itself four times.
        yield 'quoted in inline code' => ['Never write `the previous version of this section` in a page.'];
        yield 'quoted currency hedge' => ['The phrase `at time of writing` is what this rule looks for.'];
    }

    /**
     * @param non-empty-string $prose
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('proseAboutTheDocumentProvider')]
    public function itReportsProseAboutTheDocument(string $prose, string $expected): void
    {
        $findings = $this->scanner->scanText($prose);

        self::assertCount(1, $findings, \sprintf('must report: %s', $prose));
        self::assertSame($expected, $findings[0]->matched);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function proseAboutTheDocumentProvider(): Iterator
    {
        yield 'previous version of this section' => [
            'The previous version of this section listed seventeen rules.',
            self::PREVIOUS_VERSION,
        ];
        yield 'currency caveat' => [
            'The always-on set is 17 rules at time of writing.',
            self::AT_TIME_OF_WRITING,
        ];
        yield 'this page used to' => [
            'This page used to document the Bash entry point.',
            'this page used to',
        ];
        yield 'section has been moved' => [
            'This section has been moved to the configuration guide.',
            'this section has been moved',
        ];
        yield 'copy here maintained by hand' => [
            'A numbered copy here was maintained by hand.',
            'copy here was maintained by hand',
        ];
        yield 'no longer lists' => [
            'This README no longer lists every rule.',
            'this readme no longer lists',
        ];
    }

    private function project(): TempDir
    {
        $this->project = TempDir::create('dsr-scan');

        return $this->project;
    }
}
