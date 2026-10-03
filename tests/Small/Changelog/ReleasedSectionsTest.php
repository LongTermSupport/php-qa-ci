<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleasedSections;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReleasedSections::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ReleasedSectionDto::class)]
#[UsesClass(InvalidChangelogException::class)]
#[UsesClass(ReleaseLine::class)]
#[UsesClass(ReleaseVersionPolicy::class)]
#[Small]
final class ReleasedSectionsTest extends TestCase
{
    private const string BASE = "# Changelog\n\nIntro.\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n\n## 85.0.0 — 2026-10-02\n\n### Added\n\n- The first release.\n";

    private const string V850 = '85.0.0';

    private const string V851 = '85.1.0';

    private const string V852 = '85.2.0';

    private const string V150 = '1.5.0';

    private const string RELEASED = "# Changelog\n\nIntro.\n\n## Unreleased\n\n## 85.1.0 — 2026-10-09\n\n### Fixed\n\n- A fix.\n\n### Changed — breaking\n\n- A new requirement.\n\n## 85.0.0 — 2026-10-02\n\n### Added\n\n- The first release.\n";

    #[Test]
    public function versionsAreTheVersionSectionsInFileOrder(): void
    {
        self::assertSame([self::V851, self::V850], new ReleasedSections()->versions(self::RELEASED));
    }

    #[Test]
    public function aHeadingThatIsNotAVersionIsNoReleasedSection(): void
    {
        $markdown = "## Unreleased\n\n## 85.1.0\n\n## v85.0.0 — x\n\n## 85.0 — x\n\n## 85.0.0-rc1\n\n### 85.0.1\n\n## 84.9.9 — 2026-01-01\n";

        self::assertSame([self::V851, '84.9.9'], new ReleasedSections()->versions($markdown));
    }

    #[Test]
    public function aWindowsLineEndedVersionHeadingIsAVersionSection(): void
    {
        self::assertSame([self::V851, self::V850], new ReleasedSections()->versions("## Unreleased\r\n\r\n## 85.1.0\r\n\r\n## 85.0.0 — 2026-10-02\r\n"));
    }

    #[Test]
    public function sinceIsAListWhereverTheNewSectionsSit(): void
    {
        $markdown = self::RELEASED . "\n## 84.9.0 — 2026-09-30\n\n### Fixed\n\n- A backported fix.\n";

        $since = new ReleasedSections()->since($markdown, self::RELEASED);

        self::assertSame([0], array_keys($since));
        self::assertSame('84.9.0 — 2026-09-30', $since[0]->title);
    }

    #[Test]
    public function sinceIsEverySectionTheBaseDoesNotHave(): void
    {
        $since = new ReleasedSections()->since(self::RELEASED, self::BASE);

        self::assertCount(1, $since);
        self::assertSame('85.1.0 — 2026-10-09', $since[0]->title);
        self::assertSame(
            [ChangelogHeadingEnum::Fixed, ChangelogHeadingEnum::ChangedBreaking],
            array_map(static fn (ChangelogHeadingBlockDto $block): ChangelogHeadingEnum => $block->heading, $since[0]->blocks),
        );
    }

    #[Test]
    public function withNoBaseEverySectionIsNew(): void
    {
        self::assertSame(
            ['85.1.0 — 2026-10-09', '85.0.0 — 2026-10-02'],
            array_map(static fn (ReleasedSectionDto $section): string => $section->title, new ReleasedSections()->since(self::RELEASED, null)),
        );
    }

    #[Test]
    public function nothingIsNewWhenTheBaseHasEverySection(): void
    {
        self::assertSame([], new ReleasedSections()->since(self::BASE, self::BASE));
    }

    #[Test]
    public function untaggedIsEveryVersionOnTheLineNewerThanItsNewestTagOldestFirst(): void
    {
        $markdown = "## Unreleased\n\n## 85.2.0 — c\n\n### Added\n\n- C.\n\n## 85.1.0 — b\n\n### Added\n\n- B.\n\n## 85.0.0 — a\n\n### Added\n\n- A.\n";

        self::assertSame([self::V851, self::V852], new ReleasedSections()->untagged($markdown, $this->line85(), '84.3.0', self::V850));
        self::assertSame([self::V852], new ReleasedSections()->untagged($markdown, $this->line85(), self::V851, self::V850));
        self::assertSame([], new ReleasedSections()->untagged($markdown, $this->line85(), self::V852));
    }

    #[Test]
    public function aLineWithNoTagHasEveryVersionUntaggedAndOtherLinesAreIgnored(): void
    {
        $markdown = "## Unreleased\n\n## 85.0.0 — a\n\n### Added\n\n- A.\n\n## 84.7.0 — z\n\n### Added\n\n- Z.\n";

        self::assertSame([self::V850], new ReleasedSections()->untagged($markdown, $this->line85(), '84.6.0'));
    }

    #[Test]
    public function versionsCompareNumericallyNotAsText(): void
    {
        $markdown = "## Unreleased\n\n## 85.10.0 — b\n\n### Added\n\n- B.\n\n## 85.9.0 — a\n\n### Added\n\n- A.\n";

        self::assertSame(['85.10.0'], new ReleasedSections()->untagged($markdown, $this->line85(), '85.9.0'));
    }

    #[Test]
    public function underSemanticVersioningEveryVersionNewerThanTheNewestTagIsUntagged(): void
    {
        $markdown = "## Unreleased\n\n## 2.0.0 — c\n\n### Removed\n\n- C.\n\n## 1.5.0 — b\n\n### Added\n\n- B.\n\n## 1.4.2 — a\n\n### Fixed\n\n- A.\n";
        $line     = ReleaseVersionPolicy::semanticVersioning()->line('{}');

        self::assertSame([self::V150, '2.0.0'], new ReleasedSections()->untagged($markdown, $line, '1.4.2', 'v9.0.0'));
        self::assertSame(['1.4.2', self::V150, '2.0.0'], new ReleasedSections()->untagged($markdown, $line));
    }

    #[Test]
    public function aTagPrefixIsStrippedBeforeTheComparison(): void
    {
        $markdown = "## Unreleased\n\n## 1.5.0 — b\n\n### Added\n\n- B.\n\n## 1.4.2 — a\n\n### Fixed\n\n- A.\n";

        self::assertSame([self::V150], new ReleasedSections()->untagged($markdown, ReleaseVersionPolicy::semanticVersioning('v')->line('{}'), 'v1.4.2', self::V150));
    }

    private function line85(): ReleaseLine
    {
        return ReleaseVersionPolicy::lockedMajor(85)->line('{}');
    }
}
