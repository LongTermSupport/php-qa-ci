<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ProjectComposerTypeReader}: the injectable seam that tells
 * the PHPStan classification rule what kind of package it is analysing.
 *
 * `effectiveType()` normalises the declared `composer.json` `type` (trimmed,
 * lower-cased) and falls back to Composer's own silent default of `library` when
 * the field is absent / blank / not a string — so an undeclared package is treated
 * as a library (the safe, enforce-the-surface side). The separate
 * {@see \LTS\PHPQA\PackageType\ExplicitPackageTypeDetector} hard-fails the missing
 * declaration; this reader must never throw on it.
 *
 * @internal
 */
#[CoversClass(ProjectComposerTypeReader::class)]
#[Small]
final class ProjectComposerTypeReaderTest extends TestCase
{
    #[Test]
    public function itReturnsAnExplicitlyDeclaredType(): void
    {
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => 'library']))->effectiveType());
        self::assertSame('project', (new ProjectComposerTypeReader(['type' => 'project']))->effectiveType());
        self::assertSame('metapackage', (new ProjectComposerTypeReader(['type' => 'metapackage']))->effectiveType());
    }

    #[Test]
    public function itNormalisesCaseAndSurroundingWhitespace(): void
    {
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => '  Library '])) ->effectiveType());
        self::assertSame('project', (new ProjectComposerTypeReader(['type' => 'PROJECT']))->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsAbsent(): void
    {
        self::assertSame('library', (new ProjectComposerTypeReader(['name' => 'acme/widget']))->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsBlank(): void
    {
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => '']))->effectiveType());
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => '   ']))->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsNotAString(): void
    {
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => null]))->effectiveType());
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => ['library']]))->effectiveType());
        self::assertSame('library', (new ProjectComposerTypeReader(['type' => 123]))->effectiveType());
    }
}
