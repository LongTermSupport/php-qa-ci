<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\ReleaseNotesRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReleaseNotesRenderer::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ReleasedSectionDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[Small]
final class ReleaseNotesRendererTest extends TestCase
{
    #[Test]
    public function aFixReleaseIsTheTitleAndTheEntriesUnderPlainTextHeadings(): void
    {
        $notes = $this->render("## Unreleased\n\n## 85.0.1 — 2026-10-02\n\n### Fixed\n\n- One fix,\n  two lines.\n\n- Two.\n\n### Security\n\n- Patched.\n\n## 85.0.0 — 2026-09-01\n\n### Added\n\n- Not this.\n", '85.0.1');

        self::assertSame("85.0.1 — 2026-10-02\n\nFixed\n-----\n\n- One fix,\n  two lines.\n\n- Two.\n\nSecurity\n--------\n\n- Patched.\n", $notes);
    }

    #[Test]
    public function aBreakingReleaseSaysSoFirstAndNamesTheHeadingsToRead(): void
    {
        $notes = $this->render("## Unreleased\n\n## 85.1.0 — 2026-10-02\n\n### Changed — breaking\n\n- Exit 75.\n\n### Removed\n\n- Gone.\n\n### Added\n\n- New.\n", '85.1.0');

        self::assertSame(
            "85.1.0 — 2026-10-02\n\n"
            . "BREAKING: this release changes or removes behaviour a consuming project may rely on. Read \"Changed — breaking\" and \"Removed\" below before upgrading.\n\n"
            . "Changed — breaking\n------------------\n\n- Exit 75.\n\n"
            . "Removed\n-------\n\n- Gone.\n\n"
            . "Added\n-----\n\n- New.\n",
            $notes,
        );
    }

    #[Test]
    public function aSingleBreakingHeadingIsNamedAlone(): void
    {
        $notes = $this->render("## Unreleased\n\n## 85.2.0 — 2026-10-03\n\n### Removed\n\n- Gone.\n", '85.2.0');

        self::assertStringContainsString('Read "Removed" below before upgrading.', $notes);
    }

    #[Test]
    public function headingsWrittenWithoutBlankLinesKeepEveryEntryUnderItsOwnHeading(): void
    {
        $notes = $this->render("## Unreleased\n\n## 85.0.2 — 2026-10-04\n### Fixed\n- A fix.\n### Security\n- Patched.\n", '85.0.2');

        self::assertSame("85.0.2 — 2026-10-04\n\nFixed\n-----\n\n- A fix.\n\nSecurity\n--------\n\n- Patched.\n", $notes);
    }

    #[Test]
    public function noLineOfTheNotesStartsWithAHashThatGitWouldStripAsAComment(): void
    {
        $notes = $this->render("## Unreleased\n\n## 85.1.0 — 2026-10-02\n\n### Changed — breaking\n\n- Exit 75.\n\n### Fixed\n\n- A fix.\n", '85.1.0');

        foreach (explode("\n", $notes) as $line) {
            self::assertStringStartsNotWith('#', $line);
        }
    }

    private function render(string $markdown, string $version): string
    {
        return new ReleaseNotesRenderer()->render(new ChangelogParser()->release($markdown, $version));
    }
}
