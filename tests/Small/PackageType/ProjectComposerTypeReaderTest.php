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
    private const string PACKAGE_TYPE_LIBRARY = 'library';

    private const string PACKAGE_TYPE_PROJECT = 'project';

    private const string ENFORCE_AUTO = 'auto';

    private const string PACKAGE_TYPE_COMPOSER_PLUGIN = 'composer-plugin';

    private const string ENFORCE_ALWAYS = 'always';

    #[Test]
    public function itReturnsAnExplicitlyDeclaredType(): void
    {
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_LIBRARY])->effectiveType());
        self::assertSame(self::PACKAGE_TYPE_PROJECT, new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_PROJECT])->effectiveType());
        self::assertSame('metapackage', new ProjectComposerTypeReader(['type' => 'metapackage'])->effectiveType());
    }

    #[Test]
    public function itNormalisesCaseAndSurroundingWhitespace(): void
    {
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => '  Library '])->effectiveType());
        self::assertSame(self::PACKAGE_TYPE_PROJECT, new ProjectComposerTypeReader(['type' => 'PROJECT'])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsAbsent(): void
    {
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['name' => 'acme/widget'])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsBlank(): void
    {
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => ''])->effectiveType());
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => '   '])->effectiveType());
    }

    #[Test]
    public function itDefaultsToLibraryWhenTheTypeIsNotAString(): void
    {
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => null])->effectiveType());
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => [self::PACKAGE_TYPE_LIBRARY]])->effectiveType());
        self::assertSame(self::PACKAGE_TYPE_LIBRARY, new ProjectComposerTypeReader(['type' => 123])->effectiveType());
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
        yield 'auto + library — enforce'             => [self::PACKAGE_TYPE_LIBRARY, self::ENFORCE_AUTO, true];
        yield 'auto + project — no enforce'          => [self::PACKAGE_TYPE_PROJECT, self::ENFORCE_AUTO, false];
        yield 'auto + composer-plugin — no enforce'  => [self::PACKAGE_TYPE_COMPOSER_PLUGIN, self::ENFORCE_AUTO, false];

        // always: enforce regardless of type — the opt-in for a package that IS a
        // consumable library but must carry a non-library type (e.g. a composer-plugin).
        yield 'always + composer-plugin — enforce'   => [self::PACKAGE_TYPE_COMPOSER_PLUGIN, self::ENFORCE_ALWAYS, true];
        yield 'always + project — enforce'           => [self::PACKAGE_TYPE_PROJECT, self::ENFORCE_ALWAYS, true];
        yield 'always + library — enforce'           => [self::PACKAGE_TYPE_LIBRARY, self::ENFORCE_ALWAYS, true];

        // never: force off regardless of type.
        yield 'never + library — no enforce'         => [self::PACKAGE_TYPE_LIBRARY, 'never', false];
        yield 'never + composer-plugin — no enforce' => [self::PACKAGE_TYPE_COMPOSER_PLUGIN, 'never', false];
    }

    #[Test]
    public function theEnforceModeIsCaseInsensitiveAndTrimmed(): void
    {
        self::assertTrue(
            new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_COMPOSER_PLUGIN], '  ALWAYS ')->enforcesApiSurface(),
        );
    }

    #[Test]
    public function itDefaultsToAutoEnforcementWhenNoModeIsGiven(): void
    {
        // No enforce mode argument → auto → library enforces, non-library does not.
        self::assertTrue(new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_LIBRARY])->enforcesApiSurface());
        self::assertFalse(new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_COMPOSER_PLUGIN])->enforcesApiSurface());
    }

    #[Test]
    public function itRejectsAnUnknownEnforceMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Invalid phpqaciApiOrInternal.enforce value "sometimes"; expected one of: auto, always, never.');

        new ProjectComposerTypeReader(['type' => self::PACKAGE_TYPE_LIBRARY], 'sometimes');
    }
}
