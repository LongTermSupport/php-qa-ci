<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Markdown;

use Iterator;
use LTS\PHPQA\Markdown\DocumentSelfReferenceFinding;
use LTS\PHPQA\Markdown\DocumentSelfReferenceScanner;
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

    private DocumentSelfReferenceScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new DocumentSelfReferenceScanner();
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

        self::assertContains('at time of writing', $matched);
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

        self::assertSame(['README.md', 'docs/tool.md'], $files);
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
            'at time of writing',
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
}
