<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\CoverageDriverEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CoverageDriverEnum::class)]
#[Small]
final class CoverageDriverEnumTest extends TestCase
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const string PROJECT = '/srv/project';

    #[Test]
    #[DataProvider('loadedExtensions')]
    public function theDriverIsPcovWhenEnabledThenXdebugThenNone(bool $pcovEnabled, bool $xdebugLoaded, CoverageDriverEnum $expected): void
    {
        self::assertSame($expected, CoverageDriverEnum::select($pcovEnabled, $xdebugLoaded));
    }

    /** @return iterable<string, array{bool, bool, CoverageDriverEnum}> */
    public static function loadedExtensions(): iterable
    {
        yield 'pcov only' => [true, false, CoverageDriverEnum::Pcov];
        yield 'xdebug only' => [false, true, CoverageDriverEnum::Xdebug];
        yield 'both: pcov is preferred' => [true, true, CoverageDriverEnum::Pcov];
        yield 'neither' => [false, false, CoverageDriverEnum::None];
    }

    #[Test]
    public function onlyNoneGeneratesNoCoverage(): void
    {
        self::assertTrue(CoverageDriverEnum::Pcov->generatesCoverage());
        self::assertTrue(CoverageDriverEnum::Xdebug->generatesCoverage());
        self::assertFalse(CoverageDriverEnum::None->generatesCoverage());
    }

    #[Test]
    public function eachDriverHasTheNameItIsKnownBy(): void
    {
        self::assertSame('pcov', CoverageDriverEnum::Pcov->label());
        self::assertSame('Xdebug', CoverageDriverEnum::Xdebug->label());
        self::assertSame('none', CoverageDriverEnum::None->label());
    }

    #[Test]
    public function aPcovRunSwitchesXdebugOffSoTheTwoDoNotBothCollect(): void
    {
        self::assertSame([self::XDEBUG_MODE => 'off'], CoverageDriverEnum::Pcov->environment());
    }

    #[Test]
    public function anXdebugRunPutsXdebugInCoverageMode(): void
    {
        self::assertSame([self::XDEBUG_MODE => 'coverage'], CoverageDriverEnum::Xdebug->environment());
    }

    #[Test]
    public function aRunWithoutADriverSwitchesXdebugOff(): void
    {
        self::assertSame([self::XDEBUG_MODE => 'off'], CoverageDriverEnum::None->environment());
    }

    #[Test]
    public function pcovIsEnabledOverTheWholeProjectExceptItsVendorDirectory(): void
    {
        self::assertSame(
            [
                'pcov.enabled'   => '1',
                'pcov.directory' => self::PROJECT,
                'pcov.exclude'   => '~^/srv/project/vendor/~',
            ],
            CoverageDriverEnum::Pcov->phpIni(self::PROJECT),
        );
    }

    #[Test]
    public function theVendorExclusionIsAnchoredAndItsRootQuoted(): void
    {
        $exclude = CoverageDriverEnum::Pcov->phpIni('/home/a.b/vendor/lts/php-qa-ci~x')['pcov.exclude'];

        self::assertSame('~^/home/a\.b/vendor/lts/php\-qa\-ci\~x/vendor/~', $exclude);
        // The project's own files are kept even when the project sits under someone's vendor/.
        self::assertSame(0, \Safe\preg_match($exclude, '/home/a.b/vendor/lts/php-qa-ci~x/src/Foo.php'));
        self::assertSame(1, \Safe\preg_match($exclude, '/home/a.b/vendor/lts/php-qa-ci~x/vendor/acme/lib/Bar.php'));
    }

    #[Test]
    public function xdebugAndNoneNeedNoIni(): void
    {
        self::assertSame([], CoverageDriverEnum::Xdebug->phpIni(self::PROJECT));
        self::assertSame([], CoverageDriverEnum::None->phpIni(self::PROJECT));
    }
}
