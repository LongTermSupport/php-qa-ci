<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogParser::class)]
#[CoversClass(ChangelogDocumentDto::class)]
#[CoversClass(ChangelogHeadingBlockDto::class)]
#[CoversClass(ReleasedSectionDto::class)]
#[CoversClass(InvalidChangelogException::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[Small]
final class ChangelogParserTest extends TestCase
{
    private const string PREAMBLE = "# Changelog\n\nIntro text.\n\n";

    private const string ENTRY_ONE = '- One.';

    private const string RELEASED ="## 85.0.0 — 2026-09-01\n\n### Added\n\n- An old feature.\n";

    #[Test]
    public function aValidSectionYieldsItsHeadingsEntriesAndBump(): void
    {
        $document = $this->parse(self::PREAMBLE . "## Unreleased\n\n### Changed — breaking\n\n- **Exit 75.** A contended\n  lock no longer exits 1.\n\n- Second entry.\n\n### Fixed\n\n* A fix.\n\n" . self::RELEASED);

        self::assertFalse($document->isEmpty());
        self::assertSame(ReleaseBumpEnum::Major, $document->bump());
        self::assertSame(4, $document->unreleasedIndex);
        self::assertSame(17, $document->sectionEnd);
        self::assertSame(["- **Exit 75.** A contended\n  lock no longer exits 1.", '- Second entry.', '* A fix.'], $document->entries());

        [$breaking, $fixed] = $document->blocks;
        self::assertSame(ChangelogHeadingEnum::ChangedBreaking, $breaking->heading);
        self::assertSame(6, $breaking->headingIndex);
        self::assertSame(11, $breaking->lastIndex);
        self::assertSame(ChangelogHeadingEnum::Fixed, $fixed->heading);
        self::assertSame(13, $fixed->headingIndex);
        self::assertSame(15, $fixed->lastIndex);
        self::assertSame($fixed, $document->block(ChangelogHeadingEnum::Fixed));
        self::assertNull($document->block(ChangelogHeadingEnum::Added));
    }

    #[Test]
    public function fixesAndSecurityAloneArePatch(): void
    {
        $document = $this->parse("## Unreleased\n\n### Fixed\n\n- A fix.\n\n### Security\n\n- A patch.\n");

        self::assertSame(ReleaseBumpEnum::Patch, $document->bump());
    }

    #[Test]
    public function aMinorHeadingAfterPatchHeadingsMakesTheReleaseMinor(): void
    {
        $document = $this->parse("## Unreleased\n\n### Fixed\n\n- A fix.\n\n### Deprecated\n\n- Going.\n");

        self::assertSame(ReleaseBumpEnum::Minor, $document->bump());
    }

    #[Test]
    public function aBreakingHeadingAnywhereMakesTheReleaseMajor(): void
    {
        $document = $this->parse("## Unreleased\n\n### Added\n\n- New.\n\n### Fixed\n\n- A fix.\n\n### Removed\n\n- Gone.\n");

        self::assertSame(ReleaseBumpEnum::Major, $document->bump());
    }

    #[Test]
    public function anEmptySectionDuesNoRelease(): void
    {
        $document = $this->parse(self::PREAMBLE . "## Unreleased\n\n\n" . self::RELEASED);

        self::assertTrue($document->isEmpty());
        self::assertNull($document->bump());
        self::assertSame([], $document->entries());
        self::assertSame(7, $document->sectionEnd);
    }

    #[Test]
    public function theSectionMayRunToTheEndOfTheFile(): void
    {
        $document = $this->parse("## Unreleased\n\n### Added\n\n- New.");

        self::assertSame(5, $document->sectionEnd);
        self::assertSame(['- New.'], $document->entries());
        self::assertSame(['## Unreleased', '', '### Added', '', '- New.'], $document->lines);
    }

    #[Test]
    public function aMissingSectionIsInvalid(): void
    {
        self::assertSame(['no "## Unreleased" section'], $this->problems(self::PREAMBLE . self::RELEASED));
    }

    #[Test]
    public function aDuplicatedSectionIsInvalid(): void
    {
        self::assertSame(
            ['line 3: "## Unreleased" repeats the section at line 1'],
            $this->problems("## Unreleased\n\n## Unreleased\n"),
        );
    }

    #[Test]
    public function anUnknownHeadingIsInvalidAndNamesTheAllowedOnes(): void
    {
        self::assertSame(
            ['line 3: "### Improved" is not an allowed heading; use one of: Changed — breaking, Removed, Added, Changed, Deprecated, Fixed, Security'],
            $this->problems("## Unreleased\n\n### Improved\n\n- Something.\n"),
        );
    }

    #[Test]
    public function aDuplicatedHeadingIsInvalid(): void
    {
        self::assertSame(
            ['line 7: "### Fixed" repeats the heading at line 3'],
            $this->problems("## Unreleased\n\n### Fixed\n\n- One.\n\n### Fixed\n\n- Two.\n"),
        );
    }

    #[Test]
    public function anEmptyHeadingIsInvalid(): void
    {
        self::assertSame(
            ['line 3: "### Added" has no entries'],
            $this->problems("## Unreleased\n\n### Added\n\n### Fixed\n\n- One.\n"),
        );
    }

    #[Test]
    public function textOutsideAHeadingIsInvalid(): void
    {
        self::assertSame(
            ['line 3: text outside a "###" heading: "Some prose."', 'line 4: text outside a "###" heading: "- A stray entry."'],
            $this->problems("## Unreleased\n\nSome prose.\n- A stray entry.\n\n### Fixed\n\n- One.\n"),
        );
    }

    #[Test]
    public function textUnderAHeadingThatIsNotAListEntryIsInvalid(): void
    {
        self::assertSame(
            ['line 5: under "### Fixed" but not a "- " list entry: "Lazy text."', 'line 6: under "### Fixed" but not a "- " list entry: "  indented before any entry"'],
            $this->problems("## Unreleased\n\n### Fixed\n\nLazy text.\n  indented before any entry\n- One.\n"),
        );
    }

    #[Test]
    public function aDeeperHeadingIsNotAnAllowedHeading(): void
    {
        self::assertSame(
            ['line 5: "#### Detail" is not an allowed heading; only "###" headings belong in the section'],
            $this->problems("## Unreleased\n\n### Fixed\n\n#### Detail\n- One.\n"),
        );
    }

    #[Test]
    public function everyProblemIsReportedTogetherInTheMessage(): void
    {
        try {
            $this->parse("## Unreleased\n\nProse.\n\n### Bogus\n\n### Added\n");
            self::fail('expected the changelog to be invalid');
        } catch (InvalidChangelogException $invalidChangelogException) {
            self::assertCount(3, $invalidChangelogException->problems);
            self::assertSame(
                "CHANGELOG.md is invalid:\n  - line 3: text outside a \"###\" heading: \"Prose.\"\n  - line 5: \"### Bogus\" is not an allowed heading; use one of: Changed — breaking, Removed, Added, Changed, Deprecated, Fixed, Security\n  - line 7: \"### Added\" has no entries",
                $invalidChangelogException->getMessage(),
            );
        }
    }

    #[Test]
    public function parseOrNullIsTheDocumentOrNullWhenInvalid(): void
    {
        $parser = new ChangelogParser();

        self::assertSame([self::ENTRY_ONE], $parser->parseOrNull("## Unreleased\n\n### Fixed\n\n- One.\n")?->entries());
        self::assertNull($parser->parseOrNull("## Unreleased\n\n### Fixed\n"));
        self::assertNull($parser->parseOrNull("# Changelog\n"));
    }

    #[Test]
    public function trailingWhitespaceOnAHeadingLineIsTolerated(): void
    {
        $document = $this->parse("## Unreleased  \n\n### Fixed \n\n- One.\n");

        self::assertSame(ChangelogHeadingEnum::Fixed, $document->blocks[0]->heading);
    }

    #[Test]
    public function aReleasedSectionIsFoundByItsVersion(): void
    {
        $section = new ChangelogParser()->release(self::PREAMBLE . "## Unreleased\n\n" . self::RELEASED . "\n## 84.0.0 — 2026-01-01\n\n### Fixed\n\n- Older.\n", '85.0.0');

        self::assertSame('85.0.0 — 2026-09-01', $section->title);
        self::assertCount(1, $section->blocks);
        self::assertSame(ChangelogHeadingEnum::Added, $section->blocks[0]->heading);
        self::assertSame(['- An old feature.'], $section->blocks[0]->entries);
    }

    #[Test]
    public function aReleasedSectionMatchesTheWholeVersionOnly(): void
    {
        $markdown = "## Unreleased\n\n## 85.10.0 — 2026-09-02\n\n### Removed\n\n- Gone.\n\n## 85.1.0 — 2026-09-01\n\n### Fixed\n\n- Fix.\n";

        self::assertSame('85.10.0 — 2026-09-02', new ChangelogParser()->release($markdown, '85.10.0')->title);
        self::assertSame('85.1.0 — 2026-09-01', new ChangelogParser()->release($markdown, '85.1.0')->title);
    }

    #[Test]
    public function aMissingReleasedSectionIsInvalid(): void
    {
        $this->expectException(InvalidChangelogException::class);
        $this->expectExceptionMessageIsOrContains('no "## 85.9.9" section');

        new ChangelogParser()->release(self::PREAMBLE . "## Unreleased\n\n" . self::RELEASED, '85.9.9');
    }

    #[Test]
    public function anEmptyReleasedSectionIsInvalid(): void
    {
        $this->expectException(InvalidChangelogException::class);
        $this->expectExceptionMessageIsOrContains('line 3: the "## 85.1.0 — 2026-09-01" section has no entries');

        new ChangelogParser()->release("## Unreleased\n\n## 85.1.0 — 2026-09-01\n\n", '85.1.0');
    }

    #[Test]
    public function aHeadingMayFollowItsSectionLineWithNoBlankLineBetween(): void
    {
        $document = $this->parse("## Unreleased\n### Fixed\n- One.\n");

        self::assertSame([self::ENTRY_ONE], $document->entries());
        self::assertSame(1, $document->blocks[0]->headingIndex);
        self::assertSame(2, $document->blocks[0]->lastIndex);
    }

    #[Test]
    public function aSectionDirectlyFollowedByTheNextSectionIsEmpty(): void
    {
        $document = $this->parse("## Unreleased\n## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Old.\n");

        self::assertTrue($document->isEmpty());
        self::assertSame(1, $document->sectionEnd);
    }

    #[Test]
    public function aWhitespaceOnlyLineIsBlank(): void
    {
        $document = $this->parse("## Unreleased\n   \n### Fixed\n\n- One.\n \t \n- Two.\n");

        self::assertSame([self::ENTRY_ONE, '- Two.'], $document->entries());
    }

    #[Test]
    public function trailingWhitespaceIsDroppedFromEntriesAndTheirContinuations(): void
    {
        $document = $this->parse("## Unreleased\n\n### Fixed\n\n- One,  \n  continued.\t\n- Two. \n");

        self::assertSame(["- One,\n  continued.", '- Two.'], $document->entries());
    }

    #[Test]
    public function aWindowsLineEndedFileParsesAsItsUnixEquivalent(): void
    {
        $document = $this->parse("## Unreleased\r\n\r\n### Fixed\r\n\r\n- One.\r\n");

        self::assertSame([self::ENTRY_ONE], $document->entries());
        self::assertSame(ChangelogHeadingEnum::Fixed, $document->blocks[0]->heading);
    }

    #[Test]
    public function aProblemQuotesItsLineWithoutTrailingWhitespace(): void
    {
        self::assertSame(
            [
                'line 3: text outside a "###" heading: "Prose."',
                'line 7: "#### Detail" is not an allowed heading; only "###" headings belong in the section',
                'line 8: under "### Fixed" but not a "- " list entry: "Lazy text."',
            ],
            $this->problems("## Unreleased\n\nProse.  \n\n### Fixed\n\n#### Detail \t\nLazy text.   \n- One.\n"),
        );
    }

    #[Test]
    public function problemsAfterAnUnknownHeadingAreStillReported(): void
    {
        self::assertSame(
            [
                'line 3: "### Bogus" is not an allowed heading; use one of: Changed — breaking, Removed, Added, Changed, Deprecated, Fixed, Security',
                'line 9: under "### Fixed" but not a "- " list entry: "Stray."',
            ],
            $this->problems("## Unreleased\n\n### Bogus\n\n- Ignored.\n\n### Fixed\n\nStray.\n- One.\n"),
        );
    }

    #[Test]
    public function aRepeatedHeadingIsReportedOnceAndItsEntriesAreNotJudged(): void
    {
        self::assertSame(
            ['line 7: "### Fixed" repeats the heading at line 3', 'line 9: "### Fixed" repeats the heading at line 3'],
            $this->problems("## Unreleased\n\n### Fixed\n\n- One.\n\n### Fixed\n\n### Fixed\n\nNot an entry, but under a repeated heading.\n"),
        );
    }

    #[Test]
    public function aReleasedSectionHeadingMayCarryTrailingWhitespace(): void
    {
        $section = new ChangelogParser()->release("## Unreleased\n\n## 85.0.0 — 2026-09-01  \n### Fixed\n- A fix.\n", '85.0.0');

        self::assertSame('85.0.0 — 2026-09-01', $section->title);
        self::assertSame(['- A fix.'], $section->blocks[0]->entries);
    }

    #[Test]
    public function aReleasedVersionDoesNotMatchALongerVersionItPrefixes(): void
    {
        $markdown = "## Unreleased\n\n## 85.1.10 — 2026-09-03\n\n### Fixed\n\n- Tenth.\n\n## 85.1.1 — 2026-09-01\n\n### Fixed\n\n- First.\n";

        $section = new ChangelogParser()->release($markdown, '85.1.1');

        self::assertSame('85.1.1 — 2026-09-01', $section->title);
        self::assertSame(['- First.'], $section->blocks[0]->entries);
    }

    private function parse(string $markdown): ChangelogDocumentDto
    {
        return new ChangelogParser()->parse($markdown);
    }

    /** @return list<string> */
    private function problems(string $markdown): array
    {
        try {
            $this->parse($markdown);
        } catch (InvalidChangelogException $invalidChangelogException) {
            return $invalidChangelogException->problems;
        }

        self::fail('expected the changelog to be invalid');
    }
}
