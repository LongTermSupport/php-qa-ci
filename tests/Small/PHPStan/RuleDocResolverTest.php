<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\RuleDocResolver;
use LTS\PHPQA\PHPStan\Rules\ForbidEmptyCatchBlockRule;
use LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingFalseRule;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is the mechanism behind `bin/rule-doc <identifier>`: the one
 * string a PHPStan failure prints must lead, offline and from the installed
 * package, to the rule's documentation. These tests pin that contract and
 * audit it over every identifier the index publishes.
 *
 * @internal
 */
#[CoversClass(RuleDocResolver::class)]
#[UsesClass(RuleDocEntryDto::class)]
#[UsesClass(PhpstanDocsCatalogue::class)]
#[Small]
final class RuleDocResolverTest extends TestCase
{
    /** The real index, so the audit tests measure what the package actually publishes. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /**
     * A hand-built index carrying the row shapes the real one does not: a row with no
     * link at all, and a row whose link text contains a bracketed span. Every rule this
     * package ships is documented, so the page-less branch has no live example — and a
     * test that borrowed one would fail the moment that rule got its page.
     */
    private const string FIXTURE_ROOT = __DIR__ . '/../../assets/ruleDocResolver';

    /** Its own root: the resolver parses every row on any lookup, so a dead link is contagious. */
    private const string DEAD_LINK_ROOT = __DIR__ . '/../../assets/ruleDocResolverDeadLink';

    /** The shipped rule these tests use as a worked example, as the index spells its class. */
    private const string EMPTY_CATCH_RULE_CLASS = 'ForbidEmptyCatchBlockRule';

    /** A project row whose summary column is not the trailing cell. */
    private const string PROJECT_RULE = 'fixtureApp.projectRule';

    /** The same, for the row that also carries a remediation link. */
    private const string PROJECT_RULE_LINKED = 'fixtureApp.projectRuleLinked';

    /** Where a project declares its own indexes, relative to the project root. */
    private const string DECLARATION = 'qaConfig/rule-docs.json';

    /** Where the resolver reads this package's own index, relative to the library root. */
    private const string OWN_INDEX = 'docs/phpstan-rules/README.md';

    /** The carried PHPStan catalogue's version file: the phar version, then the source it came from. */
    private const string CARRIED_VERSION = "2.1.99\nphpstan/phpstan 2.1.x@abc1234\n";

    /** A built library or project root, removed after the test. */
    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
    }

    #[Test]
    public function anUnknownIdentifierNamesTheIndexThatListsTheKnownOnes(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'Unknown rule identifier "phpqaci.noSuchRule". Known identifiers are listed in ' . self::REPO_ROOT . '/' . self::OWN_INDEX,
        ));

        $this->resolver()->resolve('phpqaci.noSuchRule');
    }

    #[Test]
    public function renderingAnEntryPrintsItsFieldsThenTheWholePage(): void
    {
        $entry = $this->resolver()->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER);
        self::assertNotNull($entry->docPath);

        self::assertSame(
            ForbidEmptyCatchBlockRule::IDENTIFIER . "\n\n"
            . 'Rule:    ' . self::EMPTY_CATCH_RULE_CLASS . "\n"
            . "Bundle:  rules-default.neon\n"
            . 'Source:  ' . $entry->sourcePath . "\n"
            . 'Summary: ' . $entry->summary . "\n"
            . "\n" . \Safe\file_get_contents($entry->docPath),
            $this->resolver()->render(ForbidEmptyCatchBlockRule::IDENTIFIER),
        );
    }

    /**
     * The shipped index has two tables: always-on rows carry no bundle cell, and
     * opt-in rows carry one that links the neon file they are switched on from.
     */
    #[Test]
    public function aRowsBundleIsTheNeonItsBundleCellLinksOrTheDefaultWhenItHasNone(): void
    {
        $resolver = $this->resolver();

        self::assertSame('rules-default.neon', $resolver->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER)->bundle);
        self::assertSame('rules-optional.neon', $resolver->resolve(ForbidNullCoalescingFalseRule::IDENTIFIER)->bundle);
    }

    #[Test]
    public function aProjectRowsNamedSummaryIsNeverReportedAsItsBundle(): void
    {
        $entry = $this->projectResolver()->resolve(self::PROJECT_RULE);

        self::assertNotSame($entry->summary, $entry->bundle);
    }

    #[Test]
    public function aCarriedPhpstanPageIsPrintedWithItsProvenanceAndShortDescriptionAndWithoutItsFrontMatter(): void
    {
        $library = $this->library();
        $library->write(
            PhpstanDocsCatalogue::PAGES . '/fixture.carried.md',
            "---\ntitle: \"fixture.carried\"\nshortDescription: \"Quotes \\\"this\\\" back\"\n---\n\n# Body heading\n\nBody text.\n",
        );

        self::assertSame(
            "fixture.carried\n\n"
            . "Catalogue: PHPStan's, carried offline in vendor-docs/phpstan (phpstan/phpstan 2.1.x@abc1234, alongside phpstan.phar 2.1.99)\n"
            . "Summary:   Quotes \"this\" back\n"
            . "Online:    https://phpstan.org/error-identifiers/fixture.carried\n"
            . "\n# Body heading\n\nBody text.\n",
            new RuleDocResolver($library->path)->render('fixture.carried'),
        );
    }

    #[Test]
    public function aCarriedPageWithNoFrontMatterHasNoSummaryLine(): void
    {
        $library = $this->library();
        $library->write(PhpstanDocsCatalogue::PAGES . '/fixture.plain.md', "\n\n# Plain page\n");

        self::assertSame(
            "fixture.plain\n\n"
            . "Catalogue: PHPStan's, carried offline in vendor-docs/phpstan (phpstan/phpstan 2.1.x@abc1234, alongside phpstan.phar 2.1.99)\n"
            . "Online:    https://phpstan.org/error-identifiers/fixture.plain\n"
            . "\n# Plain page\n",
            new RuleDocResolver($library->path)->render('fixture.plain'),
        );
    }

    #[Test]
    public function anExtensionPageIsPrintedAsThisPackagesOwnNotAsPhpstans(): void
    {
        $library = $this->library();
        $library->write('docs/phpstan-extension-rules/fixture.extension.md', "# Extension page\n");

        self::assertSame(
            "fixture.extension\n\nCatalogue: php-qa-ci's page for a PHPStan extension it installs\n\n# Extension page\n",
            new RuleDocResolver($library->path)->render('fixture.extension'),
        );
    }

    #[Test]
    public function anIdentifierInNoCatalogueNamesTheCarriedVersionAndPhpstansOnlinePage(): void
    {
        self::assertSame(
            "noSuch.anywhere\n\n"
            . "This identifier is in none of the catalogues php-qa-ci carries offline: its own,\n"
            . "PHPStan's at 2.1.99, and the pages for the PHPStan extensions it installs. A newer PHPStan\n"
            . "or an extension php-qa-ci does not ship may print it. PHPStan documents its own at:\n"
            . "  https://phpstan.org/error-identifiers/noSuch.anywhere\n",
            new RuleDocResolver($this->library()->path)->render('noSuch.anywhere'),
        );
    }

    /**
     * This package owns the phpqaci.* namespace: a project row claiming one of
     * its identifiers would make that failure resolve to the project's page.
     */
    #[Test]
    public function aProjectRowCannotShadowAShippedIdentifier(): void
    {
        $project = $this->project(
            '{"indexes": ["rules.md"]}',
            ['rules.md' => '| `' . ForbidEmptyCatchBlockRule::IDENTIFIER . "` | `ShadowRule` | A project row claiming a shipped identifier |\n"],
        );

        self::assertSame(
            self::EMPTY_CATCH_RULE_CLASS,
            new RuleDocResolver(self::REPO_ROOT, $project->path)->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER)->ruleClass,
        );
    }

    #[Test]
    #[DataProvider('malformedDeclarations')]
    public function aMalformedDeclarationAddsNothingAndBreaksNothing(string $declaration): void
    {
        $project = $this->project($declaration, ['rules.md' => "| `fixtureApp.ignored` | `IgnoredRule` | Never read |\n"]);

        self::assertSame(
            $this->resolver()->identifiers(),
            new RuleDocResolver(self::REPO_ROOT, $project->path)->identifiers(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedDeclarations(): iterable
    {
        yield 'not an object' => ['"rules.md"'];
        yield 'no indexes key' => ['{"index": ["rules.md"]}'];
        yield 'indexes not a list' => ['{"indexes": "rules.md"}'];
    }

    /**
     * A bare class cell resolves beside its index unless the declaration names a
     * rulesDir, a class cell with a slash resolves under the project root, and a
     * declared path's leading and trailing slashes are not part of it. None of the
     * classes exists, so each path is reported exactly as it was built.
     */
    #[Test]
    public function eachProjectClassCellResolvesUnderTheRootItsDeclarationGives(): void
    {
        $project = $this->project(
            '{"indexes": ["/rules/index.md", {"path": "rules/more.md", "rulesDir": "/lib/Rules/"}]}',
            [
                'rules/index.md' => "| `fixtureApp.bareRule` | `BareRule` | A bare class cell beside its index |\n"
                    . "| `fixtureApp.pathRule` | `src/Checks/PathRule` | A class cell that is a project path |\n",
                'rules/more.md'  => "| `fixtureApp.declaredRule` | `DeclaredRule` | A bare class cell under the declared rules dir |\n",
            ],
        );
        $resolver = new RuleDocResolver(self::REPO_ROOT, $project->path);

        self::assertSame($project->path . '/rules/BareRule.php', $resolver->resolve('fixtureApp.bareRule')->sourcePath);
        self::assertSame($project->path . '/src/Checks/PathRule.php', $resolver->resolve('fixtureApp.pathRule')->sourcePath);
        self::assertSame($project->path . '/lib/Rules/DeclaredRule.php', $resolver->resolve('fixtureApp.declaredRule')->sourcePath);
    }

    /**
     * A table indented under a list item still has its header read, and a row
     * that stops short of the named summary column falls back to its last cell
     * rather than reading a cell that is not there.
     */
    #[Test]
    public function anIndentedHeaderIsReadAndAShortRowFallsBackToItsLastCell(): void
    {
        $project = $this->project(
            '{"indexes": ["rules.md"]}',
            ['rules.md' => "  | Identifier | Rule class | Summary | Origin |\n"
                . "  | --- | --- | --- | --- |\n"
                . "| `fixtureApp.indented` | `IndentedRule` | The summary column | Plan 00009 |\n"
                . "\n"
                . "| Identifier | Rule class | Origin | Notes | Summary |\n"
                . "| --- | --- | --- | --- | --- |\n"
                . "| `fixtureApp.shortRow` | `ShortRule` | A row that stops before its summary column |\n"],
        );
        $resolver = new RuleDocResolver(self::REPO_ROOT, $project->path);

        self::assertSame('The summary column', $resolver->resolve('fixtureApp.indented')->summary);
        self::assertSame('A row that stops before its summary column', $resolver->resolve('fixtureApp.shortRow')->summary);
    }

    #[Test]
    public function anIdentifierWithARemediationPageResolvesToThatPage(): void
    {
        $entry = $this->resolver()->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER);

        self::assertSame(self::EMPTY_CATCH_RULE_CLASS, $entry->ruleClass);
        self::assertNotNull($entry->docPath);
        self::assertFileExists($entry->docPath);
        self::assertStringEndsWith('docs/phpstan-rules/forbid-empty-catch-block.md', $entry->docPath);
        self::assertFileExists($entry->sourcePath);
    }

    #[Test]
    public function anIdentifierWithoutAPageStillResolvesToItsIndexEntry(): void
    {
        $entry = new RuleDocResolver(self::FIXTURE_ROOT)->resolve('phpqaci.fixtureNoPage');

        self::assertSame('FixtureNoPageRule', $entry->ruleClass);
        self::assertNull($entry->docPath);
        self::assertSame('A row whose requirement cell carries no link', $entry->summary);
    }

    /**
     * A row pointing at a page that is not there must resolve to no page, not take the
     * whole resolver down with it.
     *
     * `entries()` parses every row on any lookup, so one dead link decides the outcome of
     * every other identifier too: `bin/rule-doc` stops answering entirely, and the message
     * a practitioner gets is `An error occurred`. The tool whose job is to explain a
     * failure becomes the least explicable thing in the run.
     *
     * The dead link is still a defect — `RuleDocumentationTest` is the guard that reports
     * it — but it is a defect in one row, and the resolver should say so by returning
     * nothing for that row rather than by failing to start.
     */
    #[Test]
    public function aRowLinkingToAMissingPageDoesNotBreakTheResolver(): void
    {
        $resolver = new RuleDocResolver(self::DEAD_LINK_ROOT);

        self::assertNull($resolver->docPathFor('phpqaci.fixtureDeadLink'));
        self::assertSame(['phpqaci.fixtureDeadLink'], $resolver->identifiers());
    }

    /**
     * A summary routinely quotes the construction it is about, so the link TEXT contains
     * a bracketed span. A pattern that stops at the first `]` never reaches the `](` pair
     * and silently resolves the row to no page, while the index looks perfectly correct.
     */
    #[Test]
    public function aLinkWhoseTextContainsABracketStillResolves(): void
    {
        $entry = new RuleDocResolver(self::FIXTURE_ROOT)->resolve('phpqaci.fixtureNested');

        self::assertNotNull($entry->docPath, 'a bracketed span in link text must not break resolution');
        self::assertStringEndsWith('fixture-nested.md', $entry->docPath);
        self::assertSame('`#[\Attr]` in the link text', $entry->summary);
    }

    /**
     * A practitioner holds one string and cannot tell whose it is. For an identifier
     * in no catalogue this package carries — an extension it does not ship, such as
     * `shipmonk.deadMethod` — "Unknown rule identifier" is true and useless. The
     * honest answer says which catalogues were searched and names PHPStan's online one.
     */
    #[Test]
    public function renderingAForeignIdentifierPointsAtItsOwnCatalogueInsteadOfFailing(): void
    {
        $rendered = $this->resolver()->render('shipmonk.deadMethod');

        self::assertStringStartsWith('shipmonk.deadMethod', $rendered);
        self::assertStringContainsString('https://phpstan.org/error-identifiers/shipmonk.deadMethod', $rendered);
        self::assertStringContainsString('in none of the catalogues', $rendered);
        self::assertStringContainsString('offline', $rendered);
    }

    #[Test]
    public function everyPublishedIdentifierResolvesAndEveryLinkedPageExists(): void
    {
        $resolver    = $this->resolver();
        $identifiers = $resolver->identifiers();
        self::assertNotSame([], $identifiers);

        foreach ($identifiers as $identifier) {
            $entry = $resolver->resolve($identifier);
            self::assertFileExists($entry->sourcePath, $identifier . ' names a rule class that is not on disk');
            if (null !== $entry->docPath) {
                self::assertFileExists($entry->docPath, $identifier . ' links a remediation page that is not on disk');
            }
        }
    }

    #[Test]
    public function renderingAnEntryLeadsWithTheIdentifierAndIncludesThePage(): void
    {
        $rendered = $this->resolver()->render(ForbidEmptyCatchBlockRule::IDENTIFIER);

        self::assertStringStartsWith(ForbidEmptyCatchBlockRule::IDENTIFIER, $rendered);
        self::assertStringContainsString(self::EMPTY_CATCH_RULE_CLASS, $rendered);
        self::assertStringContainsString('## The correct construction', $rendered);
    }

    #[Test]
    public function aPipelineLaneIdentifierResolvesLikeARule(): void
    {
        $entry = $this->resolver()->resolve('phpqaci.configTemplateIgnoreList');

        self::assertSame('ConfigTemplateIgnoreList/ConfigTemplateIgnoreListCheck', $entry->ruleClass);
        self::assertFileExists($entry->sourcePath);
        self::assertNotNull($entry->docPath);
        self::assertStringEndsWith('docs/tools/configTemplateIgnoreListCheck.md', $entry->docPath);
    }

    #[Test]
    public function aConsumingProjectsOwnIdentifierResolves(): void
    {
        // Without this a project's rule prints an identifier its own developers
        // cannot look up: rule-doc answers "Unknown rule identifier" for every
        // rule the project wrote, which is most of the ones they will hit.
        $entry = $this->projectResolver()->resolve(self::PROJECT_RULE);

        self::assertSame('ProjectRule', $entry->ruleClass);
        self::assertSame('A project rule with no remediation page', $entry->summary);
    }

    #[Test]
    public function theSummaryComesFromTheNamedColumnNotTheTrailingCell(): void
    {
        // The fixture's columns are Identifier | Rule class | Forbids | Origin,
        // which is what a consuming project actually writes. Reading the last
        // cell would answer "Plan 00001" — the provenance, not the rule.
        $entry = $this->projectResolver()->resolve(self::PROJECT_RULE);

        self::assertSame('A project rule with no remediation page', $entry->summary);
        self::assertStringNotContainsString('Plan 00001', $entry->summary);
    }

    #[Test]
    public function theRemediationLinkIsFoundInTheNamedColumnToo(): void
    {
        $entry = $this->projectResolver()->resolve(self::PROJECT_RULE_LINKED);

        self::assertSame('A project rule that links to a page', $entry->summary);
        self::assertNotNull($entry->docPath);
    }

    #[Test]
    public function eachTableInAFileIsReadWithItsOwnHeader(): void
    {
        // Two tables, different summary columns, prose between them. A header
        // resolved once per file would give one of these the other's column.
        $resolver = $this->projectResolver();

        self::assertSame('A rule from a second catalogue', $resolver->resolve('fixtureApp.extraRule')->summary);
        self::assertSame('A rule whose summary column comes last', $resolver->resolve('fixtureApp.lateRule')->summary);
    }

    #[Test]
    public function anIndexWithNoRecognisedSummaryHeaderStillUsesTheTrailingCell(): void
    {
        // The shipped index heads its column "Requirement", which is not in the
        // recognised set, so the fallback is what keeps this package working.
        $entry = $this->resolver()->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER);

        self::assertNotSame('', $entry->summary);
        self::assertNotNull($entry->docPath);
    }

    #[Test]
    public function aProjectRowsRemediationPageResolvesRelativeToItsOwnIndex(): void
    {
        $entry = $this->projectResolver()->resolve(self::PROJECT_RULE_LINKED);

        self::assertNotNull($entry->docPath);
        self::assertFileExists($entry->docPath);
        self::assertStringEndsWith('ruleDocProject/docs/project-rule.md', $entry->docPath);
        self::assertStringContainsString('Project rule remediation page', $this->projectResolver()->render(self::PROJECT_RULE_LINKED));
    }

    #[Test]
    public function aDeclaredRulesDirResolvesABareClassCellUnderTheProject(): void
    {
        $entry = $this->projectResolver()->resolve('fixtureApp.extraRule');

        self::assertFileExists($entry->sourcePath);
        self::assertStringEndsWith('qaConfig/PHPStan/Rules/ExtraRule.php', $entry->sourcePath);
    }

    #[Test]
    public function thePackagesOwnIdentifiersStillResolveAlongsideAProjects(): void
    {
        $resolver = $this->projectResolver();

        self::assertSame(self::EMPTY_CATCH_RULE_CLASS, $resolver->resolve(ForbidEmptyCatchBlockRule::IDENTIFIER)->ruleClass);
        self::assertContains(self::PROJECT_RULE, $resolver->identifiers());
        self::assertContains(ForbidEmptyCatchBlockRule::IDENTIFIER, $resolver->identifiers());
    }

    #[Test]
    public function aProjectWithNoRuleDocsFileIsUnaffected(): void
    {
        $resolver = new RuleDocResolver(self::REPO_ROOT, self::FIXTURE_ROOT);

        self::assertSame(
            new RuleDocResolver(self::REPO_ROOT)->identifiers(),
            $resolver->identifiers(),
            'declaring no project index must change nothing',
        );
    }

    #[Test]
    public function anIdentifierInNoCatalogueIsStillReportedAsSuch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->projectResolver()->resolve('fixtureApp.noSuchRule');
    }

    private function projectResolver(): RuleDocResolver
    {
        return new RuleDocResolver(self::REPO_ROOT, __DIR__ . '/../../assets/ruleDocProject');
    }

    private function resolver(): RuleDocResolver
    {
        return new RuleDocResolver(self::REPO_ROOT);
    }

    /** A library root with an empty own index and a carried PHPStan catalogue at a known version. */
    private function library(): TempDir
    {
        $this->temp = TempDir::create('rule-doc-library');
        $this->temp->write(self::OWN_INDEX, "# An index with no rows\n");
        $this->temp->write(PhpstanDocsCatalogue::VERSION_FILE, self::CARRIED_VERSION);
        $this->temp->mkdir(PhpstanDocsCatalogue::PAGES);

        return $this->temp;
    }

    /**
     * A project root with the given rule-docs.json and index files.
     *
     * @param array<string, string> $indexes project-relative path => contents
     */
    private function project(string $declaration, array $indexes): TempDir
    {
        $this->temp = TempDir::create('rule-doc-project');
        $this->temp->write(self::DECLARATION, $declaration);
        foreach ($indexes as $path => $contents) {
            $this->temp->write($path, $contents);
        }

        return $this->temp;
    }
}
