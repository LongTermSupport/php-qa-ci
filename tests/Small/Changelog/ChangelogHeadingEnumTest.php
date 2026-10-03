<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use InvalidArgumentException;
use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogHeadingEnum::class)]
#[Small]
final class ChangelogHeadingEnumTest extends TestCase
{
    #[Test]
    public function breakingChangesAskForTheMajorFeaturesTheMinorAndFixesThePatch(): void
    {
        self::assertSame(ReleaseBumpEnum::Major, ChangelogHeadingEnum::ChangedBreaking->bump());
        self::assertSame(ReleaseBumpEnum::Major, ChangelogHeadingEnum::Removed->bump());

        $minor = [ChangelogHeadingEnum::Added, ChangelogHeadingEnum::Changed, ChangelogHeadingEnum::Deprecated];
        foreach ($minor as $heading) {
            self::assertSame(ReleaseBumpEnum::Minor, $heading->bump(), $heading->value);
        }

        self::assertSame(ReleaseBumpEnum::Patch, ChangelogHeadingEnum::Fixed->bump());
        self::assertSame(ReleaseBumpEnum::Patch, ChangelogHeadingEnum::Security->bump());
    }

    #[Test]
    public function onlyChangedBreakingAndRemovedAreBreaking(): void
    {
        $breaking = array_values(array_filter(ChangelogHeadingEnum::cases(), static fn (ChangelogHeadingEnum $heading): bool => $heading->isBreaking()));

        self::assertSame([ChangelogHeadingEnum::ChangedBreaking, ChangelogHeadingEnum::Removed], $breaking);
    }

    #[Test]
    public function theCanonicalOrderIsTheDeclarationOrder(): void
    {
        $ranks = array_map(static fn (ChangelogHeadingEnum $heading): int => $heading->rank(), ChangelogHeadingEnum::cases());

        self::assertSame([0, 1, 2, 3, 4, 5, 6], $ranks);
    }

    #[Test]
    public function anArgumentIsTheLabelOrTheSlugInAnyCase(): void
    {
        self::assertSame(ChangelogHeadingEnum::ChangedBreaking, ChangelogHeadingEnum::fromArgument('Changed — breaking'));
        self::assertSame(ChangelogHeadingEnum::ChangedBreaking, ChangelogHeadingEnum::fromArgument('changed-breaking'));
        self::assertSame(ChangelogHeadingEnum::Changed, ChangelogHeadingEnum::fromArgument('Changed'));
        self::assertSame(ChangelogHeadingEnum::Security, ChangelogHeadingEnum::fromArgument(' SECURITY '));
        self::assertSame('changed-breaking', ChangelogHeadingEnum::ChangedBreaking->slug());
        self::assertSame('deprecated', ChangelogHeadingEnum::Deprecated->slug());
    }

    #[Test]
    public function anUnknownArgumentListsTheAcceptedHeadings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown changelog heading "Improved"; use one of: Changed — breaking (changed-breaking), Removed (removed), Added (added), Changed (changed), Deprecated (deprecated), Fixed (fixed), Security (security)');

        ChangelogHeadingEnum::fromArgument('Improved');
    }
}
