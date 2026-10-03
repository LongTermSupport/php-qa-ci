<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use Iterator;
use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\ChangelogReleaseWriter;
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
#[CoversClass(ChangelogReleaseWriter::class)]
#[CoversClass(ChangelogReleaseException::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogDocumentDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[Small]
final class ChangelogReleaseWriterTest extends TestCase
{
    private const string HEAD = "# Changelog\n\nIntro.\n\n## Unreleased\n\n";

    private const string BODY = "### Added\n\n- A feature,\n  over two lines.\n\n### Fixed\n\n- A fix.\n";

    private const string OLDER = "## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Older fix.\n";

    private const string VERSION = '85.1.0';

    private const string DATE = '2026-10-02';

    private const string BAD_VERSION = 'the version must be MAJOR.MINOR.PATCH digits; got "%s"';

    private const string BAD_DATE = 'the date must be a real YYYY-MM-DD date; got "%s"';

    #[Test]
    public function theUnreleasedBodyBecomesADatedSectionUnderAFreshUnreleased(): void
    {
        $released = $this->apply(self::HEAD . self::BODY . "\n" . self::OLDER, self::VERSION);

        self::assertSame(self::HEAD . "## 85.1.0 — 2026-10-02\n\n" . self::BODY . "\n" . self::OLDER, $released);
        self::assertTrue(new ChangelogParser()->parse($released)->isEmpty());
    }

    #[Test]
    public function surroundingBlankLinesAreNormalisedAndTheRestIsByteForByte(): void
    {
        $older    = "## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Older   \n\n\n## 84.0.0\n\n- Odd spacing kept.\n";
        $released = $this->apply("# Changelog\n\n## Unreleased\n\n\n\n" . self::BODY . "\n\n\n" . $older, self::VERSION);

        self::assertSame("# Changelog\n\n## Unreleased\n\n## 85.1.0 — 2026-10-02\n\n" . self::BODY . "\n" . $older, $released);
    }

    #[Test]
    public function aSectionAtTheEndOfTheFileEndsWithOneNewline(): void
    {
        self::assertSame(
            "## Unreleased\n\n## 85.0.1 — 2026-10-02\n\n### Fixed\n\n- A fix.\n",
            $this->apply("## Unreleased\n\n### Fixed\n\n- A fix.", '85.0.1'),
        );
    }

    #[Test]
    public function anEmptySectionHasNothingToRelease(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('the "## Unreleased" section is empty: there is nothing to release');

        $this->apply(self::HEAD . self::OLDER, self::VERSION);
    }

    #[Test]
    public function anExistingSectionForTheVersionIsRefused(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('CHANGELOG.md already has a "## 85.0.0" section');

        $this->apply(self::HEAD . self::BODY . "\n" . self::OLDER, '85.0.0');
    }

    #[Test]
    public function anExistingSectionIsRecognisedWhateverItsLineEnding(): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains('CHANGELOG.md already has a "## 85.0.0" section');

        $this->apply("## Unreleased\r\n\r\n### Fixed\r\n\r\n- A fix.\r\n\r\n## 85.0.0\r\n\r\n### Fixed\r\n\r\n- Older fix.\r\n", '85.0.0');
    }

    #[Test]
    public function aVersionThatPrefixesAnExistingOneIsStillNew(): void
    {
        $older    = "## 85.1.10 — 2026-09-01\n\n### Fixed\n\n- Older fix.\n";
        $released = $this->apply(self::HEAD . self::BODY . "\n" . $older, '85.1.1');

        self::assertSame(self::HEAD . "## 85.1.1 — 2026-10-02\n\n" . self::BODY . "\n" . $older, $released);
    }

    #[Test]
    #[DataProvider('realDates')]
    public function aRealDateIsAccepted(string $date): void
    {
        self::assertStringContainsString('## 85.1.0 — ' . $date . "\n", $this->apply(self::HEAD . self::BODY, self::VERSION, $date));
    }

    /** @return Iterator<string, array{string}> */
    public static function realDates(): Iterator
    {
        yield 'a day past the twelfth' => ['2026-10-31'];
        yield 'a leap day'             => ['2028-02-29'];
    }

    #[Test]
    #[DataProvider('malformedArguments')]
    public function aMalformedVersionOrDateIsRefused(string $version, string $date, string $message): void
    {
        $this->expectException(ChangelogReleaseException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->apply(self::HEAD . self::BODY, $version, $date);
    }

    /** @return Iterator<string, array{string, string, string}> */
    public static function malformedArguments(): Iterator
    {
        foreach (['a v prefix' => 'v85.1.0', 'two parts' => '85.1', 'a suffix' => '85.1.0-rc1'] as $name => $version) {
            yield $name => [$version, self::DATE, \sprintf(self::BAD_VERSION, $version)];
        }

        foreach (['a slashed date' => '2026/10/02', 'no such day' => '2026-02-30', 'a short year' => '26-10-02', 'not a leap year' => '2026-02-29'] as $name => $date) {
            yield $name => [self::VERSION, $date, \sprintf(self::BAD_DATE, $date)];
        }
    }

    private function apply(string $markdown, string $version, string $date = self::DATE): string
    {
        return new ChangelogReleaseWriter()->apply(new ChangelogParser()->parse($markdown), $version, $date);
    }
}
