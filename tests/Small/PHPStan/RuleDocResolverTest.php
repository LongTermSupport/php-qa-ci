<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\RuleDocResolver;
use LTS\PHPQA\PHPStan\Rules\ForbidEmptyCatchBlockRule;
use PHPUnit\Framework\Attributes\CoversClass;
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

    #[Test]
    public function anUnknownIdentifierIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('phpqaci.noSuchRule');

        $this->resolver()->resolve('phpqaci.noSuchRule');
    }

    /**
     * A practitioner holds one string and cannot tell whose it is. For an identifier
     * outside php-qa-ci's prefix — PHPStan's own `method.notFound`, an extension's
     * `shipmonk.deadMethod` — "Unknown rule identifier" is true and useless. The
     * honest answer names the catalogue it belongs to and says plainly that this
     * package does not carry that catalogue offline.
     */
    #[Test]
    public function renderingAForeignIdentifierPointsAtItsOwnCatalogueInsteadOfFailing(): void
    {
        $rendered = $this->resolver()->render('method.notFound');

        self::assertStringStartsWith('method.notFound', $rendered);
        self::assertStringContainsString('https://phpstan.org/error-identifiers/method.notFound', $rendered);
        self::assertStringContainsString('not a php-qa-ci identifier', $rendered);
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
        $entry = $this->projectResolver()->resolve('fixtureApp.projectRule');

        self::assertSame('ProjectRule', $entry->ruleClass);
        self::assertSame('A project rule with no remediation page', $entry->summary);
    }

    #[Test]
    public function theSummaryComesFromTheNamedColumnNotTheTrailingCell(): void
    {
        // The fixture's columns are Identifier | Rule class | Forbids | Origin,
        // which is what a consuming project actually writes. Reading the last
        // cell would answer "Plan 00001" — the provenance, not the rule.
        $entry = $this->projectResolver()->resolve('fixtureApp.projectRule');

        self::assertSame('A project rule with no remediation page', $entry->summary);
        self::assertStringNotContainsString('Plan 00001', $entry->summary);
    }

    #[Test]
    public function theRemediationLinkIsFoundInTheNamedColumnToo(): void
    {
        $entry = $this->projectResolver()->resolve('fixtureApp.projectRuleLinked');

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
        $entry = $this->projectResolver()->resolve('fixtureApp.projectRuleLinked');

        self::assertNotNull($entry->docPath);
        self::assertFileExists($entry->docPath);
        self::assertStringEndsWith('ruleDocProject/docs/project-rule.md', $entry->docPath);
        self::assertStringContainsString('Project rule remediation page', $this->projectResolver()->render('fixtureApp.projectRuleLinked'));
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
        self::assertContains('fixtureApp.projectRule', $resolver->identifiers());
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
}
