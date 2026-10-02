<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use Iterator;
use LTS\PHPQA\Changelog\ChangelogEntryAdder;
use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogEntryAdder::class)]
#[CoversClass(ChangelogReleaseException::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogDocumentDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[Small]
final class ChangelogEntryAdderTest extends TestCase
{
    private const string OLDER = "## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Older.\n";

    private const string UNRELEASED = "## Unreleased\n";

    private const string PATCHED = 'Patched.';

    #[Test]
    public function anEntryGoesAfterTheLastEntryOfAnExistingHeading(): void
    {
        $added = $this->add("## Unreleased\n\n### Changed\n\n- First,\n  continued.\n\n### Fixed\n\n- A fix.\n\n" . self::OLDER, ChangelogHeadingEnum::Changed, 'Second.');

        self::assertSame("## Unreleased\n\n### Changed\n\n- First,\n  continued.\n\n- Second.\n\n### Fixed\n\n- A fix.\n\n" . self::OLDER, $added);
    }

    #[Test]
    public function aMissingHeadingIsCreatedBeforeTheFirstHeadingThatRanksAfterIt(): void
    {
        $added = $this->add("## Unreleased\n\n### Changed — breaking\n\n- Broke.\n\n### Fixed\n\n- A fix.\n\n" . self::OLDER, ChangelogHeadingEnum::Changed, 'Tools updated.');

        self::assertSame("## Unreleased\n\n### Changed — breaking\n\n- Broke.\n\n### Changed\n\n- Tools updated.\n\n### Fixed\n\n- A fix.\n\n" . self::OLDER, $added);
    }

    #[Test]
    public function aMissingHeadingThatRanksLastIsAppendedToTheSection(): void
    {
        $added = $this->add("## Unreleased\n\n### Added\n\n- New.\n\n" . self::OLDER, ChangelogHeadingEnum::Security, self::PATCHED);

        self::assertSame("## Unreleased\n\n### Added\n\n- New.\n\n### Security\n\n- Patched.\n\n" . self::OLDER, $added);
    }

    #[Test]
    public function aHeadingAppendedToASectionWithNoTrailingBlankLineFollowsItsLastEntry(): void
    {
        $added = $this->add("## Unreleased\n\n### Added\n\n- New.\n" . self::OLDER, ChangelogHeadingEnum::Security, self::PATCHED);

        self::assertSame("## Unreleased\n\n### Added\n\n- New.\n\n### Security\n\n- Patched.\n\n" . self::OLDER, $added);
    }

    #[Test]
    public function aHeadingAppendedAtTheEndOfAFileWithNoFinalNewlineFollowsItsLastEntry(): void
    {
        self::assertSame(
            "## Unreleased\n\n### Added\n\n- New.\n\n### Security\n\n- Patched.",
            $this->add("## Unreleased\n\n### Added\n\n- New.", ChangelogHeadingEnum::Security, self::PATCHED),
        );
    }

    #[Test]
    public function whitespaceOnlyLinesAtTheEndOfTheSectionCountAsBlank(): void
    {
        $added = $this->add("## Unreleased\n\n### Added\n\n- New.\n   \n" . self::OLDER, ChangelogHeadingEnum::Security, self::PATCHED);

        self::assertSame("## Unreleased\n\n### Added\n\n- New.\n\n### Security\n\n- Patched.\n   \n" . self::OLDER, $added);
    }

    #[Test]
    #[DataProvider('emptySections')]
    public function anEmptySectionGainsItsFirstHeading(string $before, string $after): void
    {
        self::assertSame($after, $this->add($before, ChangelogHeadingEnum::Changed, 'Tools updated.'));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function emptySections(): Iterator
    {
        $entry = "### Changed\n\n- Tools updated.\n";

        yield 'blank line before the next release' => ["# Changelog\n\n## Unreleased\n\n" . self::OLDER, "# Changelog\n\n## Unreleased\n\n" . $entry . "\n" . self::OLDER];
        yield 'no blank line before the next release' => [self::UNRELEASED . self::OLDER, self::UNRELEASED . "\n" . $entry . "\n" . self::OLDER];
        yield 'at the end of the file' => [self::UNRELEASED, self::UNRELEASED . "\n" . $entry];
        yield 'at the end with no newline' => [rtrim(self::UNRELEASED), self::UNRELEASED . "\n" . rtrim($entry, "\n")];
    }

    #[Test]
    public function theResultParsesWithTheNewEntryUnderItsHeading(): void
    {
        $added    = $this->add("## Unreleased\n\n### Fixed\n\n- A fix.\n", ChangelogHeadingEnum::ChangedBreaking, '  Requires ext-intl.  ');
        $document = new ChangelogParser()->parse($added);

        self::assertSame(['- Requires ext-intl.'], $document->block(ChangelogHeadingEnum::ChangedBreaking)?->entries);
        self::assertSame(ChangelogHeadingEnum::ChangedBreaking, $document->blocks[0]->heading);
    }

    #[Test]
    #[DataProvider('unusableTexts')]
    public function anEmptyOrMultiLineEntryIsRefused(string $text): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('a changelog entry must be one non-empty line of text');

        $this->add(self::UNRELEASED, ChangelogHeadingEnum::Fixed, $text);
    }

    /** @return Iterator<string, array{string}> */
    public static function unusableTexts(): Iterator
    {
        yield 'empty'        => [''];
        yield 'blank'        => ["  \t "];
        yield 'two lines'    => ["one\ntwo"];
        yield 'a carriage'   => ["one\rtwo"];
    }

    private function add(string $markdown, ChangelogHeadingEnum $heading, string $text): string
    {
        return new ChangelogEntryAdder()->add(new ChangelogParser()->parse($markdown), $heading, $text);
    }
}
