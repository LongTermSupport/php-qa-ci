<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use InvalidArgumentException;
use LTS\PHPQA\PackageType\ApiSurfaceEnforcementModeEnum;
use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
#[CoversClass(ApiSurfaceEnforcementModeEnum::class)]
#[Small]
final class ProjectComposerTypeReaderTest extends TestCase
{
    #[Test]
    public function itReturnsAnExplicitlyDeclaredType(): void
    {
        self::assertSame('library', new ProjectComposerTypeReader(['type' => 'library'])->effectiveType());
        self::assertSame('project', new ProjectComposerTypeReader(['type' => 'project'])->effectiveType());
        self::assertSame('metapackage', new ProjectComposerTypeReader(['type' => 'metapackage'])->effectiveType());
    }

    #[Test]
    public function itNormalisesCaseAndSurroundingWhitespace(): void
    {
        self::assertSame('library', new ProjectComposerTypeReader(['type' => '  Library '])->effectiveType());
        self::assertSame('project', new ProjectComposerTypeReader(['type' => 'PROJECT'])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsAbsent(): void
    {
        self::assertSame('library', new ProjectComposerTypeReader(['name' => 'acme/widget'])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsBlank(): void
    {
        self::assertSame('library', new ProjectComposerTypeReader(['type' => ''])->effectiveType());
        self::assertSame('library', new ProjectComposerTypeReader(['type' => '   '])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsNotAString(): void
    {
        self::assertSame('library', new ProjectComposerTypeReader(['type' => null])->effectiveType());
        self::assertSame('library', new ProjectComposerTypeReader(['type' => ['library']])->effectiveType());
        self::assertSame('library', new ProjectComposerTypeReader(['type' => 123])->effectiveType());
    }

    #[Test]
    #[DataProvider('enforcementCases')]
    public function itResolvesWhetherToEnforceApiSurface(
        string $type,
        string $enforceMode,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            new ProjectComposerTypeReader(['type' => $type], $enforceMode)->enforcesApiSurface(),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function enforcementCases(): iterable
    {
        // auto (default): enforce iff the composer type is library.
        yield 'auto + library — enforce'             => ['library', 'auto', true];
        yield 'auto + project — no enforce'          => ['project', 'auto', false];
        yield 'auto + composer-plugin — no enforce'  => ['composer-plugin', 'auto', false];

        // always: enforce regardless of type — the opt-in for a package that IS a
        // consumable library but must carry a non-library type (e.g. a composer-plugin).
        yield 'always + composer-plugin — enforce'   => ['composer-plugin', 'always', true];
        yield 'always + project — enforce'           => ['project', 'always', true];
        yield 'always + library — enforce'           => ['library', 'always', true];

        // never: force off regardless of type.
        yield 'never + library — no enforce'         => ['library', 'never', false];
        yield 'never + composer-plugin — no enforce' => ['composer-plugin', 'never', false];
    }

    #[Test]
    public function theEnforceModeIsCaseInsensitiveAndTrimmed(): void
    {
        self::assertTrue(
            new ProjectComposerTypeReader(['type' => 'composer-plugin'], '  ALWAYS ')->enforcesApiSurface(),
        );
    }

    #[Test]
    public function itDefaultsToAutoEnforcementWhenNoModeIsGiven(): void
    {
        // No enforce mode argument → auto → library enforces, non-library does not.
        self::assertTrue(new ProjectComposerTypeReader(['type' => 'library'])->enforcesApiSurface());
        self::assertFalse(new ProjectComposerTypeReader(['type' => 'composer-plugin'])->enforcesApiSurface());
    }

    #[Test]
    public function itRejectsAnUnknownEnforceMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid phpqaciApiOrInternal.enforce value "sometimes"');

        new ProjectComposerTypeReader(['type' => 'library'], 'sometimes');
    }
}
