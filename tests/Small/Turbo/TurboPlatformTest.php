<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The names here are upstream's: the directory and file names under phpstan/phpstan's
 * turbo-ext/, which is where the phar looks.
 *
 * @internal
 */
#[CoversClass(TurboPlatform::class)]
#[Small]
final class TurboPlatformTest extends TestCase
{
    private const string PHP_MINOR = '8.5';

    private const string LINUX_GNU_X86_64 = 'linux-gnu-x86_64';

    private const string BINARY_85 = 'phpstan_turbo-8.5.so';

    private const string CORE = 'phpstan_turbo_core.so';

    private const string DARWIN = 'Darwin';

    private const string LINUX = 'Linux';

    private const string X86_64 = 'x86_64';

    private const string GNU = 'gnu';

    private const string MUSL = 'musl';

    private const string ARM64 = 'arm64';

    private const string LINUX_MUSL_X86_64 = 'linux-musl-x86_64';

    private const string SELF_MAPS = '/proc/self/maps';

    private const string GLIBC_MAPS = "5581d1a00000-5581d1c00000 r--p 00000000 08:01 1 /usr/bin/php8.5\n"
        . "7f2a1c000000-7f2a1c028000 r--p 00000000 08:01 2 /usr/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2\n";

    private const string MUSL_MAPS = "5581d1a00000-5581d1c00000 r--p 00000000 08:01 1 /usr/bin/php85\n"
        . "7f2a1c000000-7f2a1c014000 r--p 00000000 08:01 2 /lib/ld-musl-x86_64.so.1\n";

    #[Test]
    #[DataProvider('supportedHosts')]
    public function aSupportedHostNamesTheBinaryAndTheCoreWherePhpstanLooks(TurboPlatform $platform, string $directory, string $binary): void
    {
        self::assertSame($directory . '/' . $binary, $platform->binaryPath());
        self::assertSame($directory . '/' . self::CORE, $platform->corePath(), 'the core sits beside the binary, which the phar refuses to load without');
    }

    /** @return iterable<string, array{TurboPlatform, string, string}> */
    public static function supportedHosts(): iterable
    {
        yield 'linux glibc x86_64' => [
            new TurboPlatform(self::LINUX, self::X86_64, self::GNU, self::PHP_MINOR, false),
            self::LINUX_GNU_X86_64,
            self::BINARY_85,
        ];
        yield 'linux glibc aarch64 is arm64' => [
            new TurboPlatform(self::LINUX, 'aarch64', self::GNU, self::PHP_MINOR, false),
            'linux-gnu-arm64',
            self::BINARY_85,
        ];
        yield 'linux musl x86_64' => [
            new TurboPlatform(self::LINUX, self::X86_64, self::MUSL, self::PHP_MINOR, false),
            self::LINUX_MUSL_X86_64,
            self::BINARY_85,
        ];
        yield 'linux zts' => [
            new TurboPlatform(self::LINUX, self::X86_64, self::GNU, '8.6', true),
            self::LINUX_GNU_X86_64,
            'phpstan_turbo-8.6-zts.so',
        ];
        yield 'amd64 is x86_64' => [
            new TurboPlatform(self::LINUX, 'amd64', self::GNU, self::PHP_MINOR, false),
            self::LINUX_GNU_X86_64,
            self::BINARY_85,
        ];
        yield 'macos arm64' => [
            new TurboPlatform(self::DARWIN, self::ARM64, '', self::PHP_MINOR, false),
            'macos-arm64',
            self::BINARY_85,
        ];
    }

    #[Test]
    #[DataProvider('unsupportedHosts')]
    public function anUnsupportedHostHasNoPaths(TurboPlatform $platform): void
    {
        self::assertNull($platform->binaryPath());
        self::assertNull($platform->corePath());
    }

    /** @return iterable<string, array{TurboPlatform}> */
    public static function unsupportedHosts(): iterable
    {
        yield 'windows' => [new TurboPlatform('Windows', 'AMD64', '', self::PHP_MINOR, false)];
        yield 'intel mac' => [new TurboPlatform(self::DARWIN, self::X86_64, '', self::PHP_MINOR, false)];
        yield 'linux on an unknown cpu' => [new TurboPlatform(self::LINUX, 'riscv64', self::GNU, self::PHP_MINOR, false)];
        yield 'linux with an unknown libc' => [new TurboPlatform(self::LINUX, self::X86_64, '', self::PHP_MINOR, false)];
        yield 'zts mac' => [new TurboPlatform(self::DARWIN, self::ARM64, '', self::PHP_MINOR, true)];
    }

    #[Test]
    public function theDescriptionNamesEveryAxisThatPicksTheBinary(): void
    {
        $description = new TurboPlatform(self::LINUX, self::X86_64, self::GNU, self::PHP_MINOR, true)->describe();

        self::assertSame('Linux x86_64, libc gnu, PHP 8.5 ZTS', $description);
    }

    #[Test]
    public function theRuntimeIsReadFromTheRunningPhp(): void
    {
        $platform = TurboPlatform::fromRuntime();

        self::assertStringContainsString('PHP ' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, $platform->describe());
        self::assertStringContainsString(\PHP_OS_FAMILY, $platform->describe());
    }

    #[Test]
    public function theRuntimeIsJudgedByTheLibrariesThisProcessHasLoaded(): void
    {
        $maps = is_readable(self::SELF_MAPS) ? \Safe\file_get_contents(self::SELF_MAPS) : null;

        $expected = TurboPlatform::fromProcess(\PHP_OS_FAMILY, php_uname('m'), $maps, is_file('/etc/alpine-release'));

        self::assertSame($expected->describe(), TurboPlatform::fromRuntime()->describe());
        self::assertSame($expected->binaryPath(), TurboPlatform::fromRuntime()->binaryPath());
    }

    #[Test]
    public function theRuntimeReadsTheMapsAndTheAlpineReleaseItIsPointedAt(): void
    {
        $tmp = TempDir::create('turbo-platform');

        try {
            $musl    = $tmp->write('maps', self::MUSL_MAPS);
            $alpine  = $tmp->write('alpine-release', "3.22.0\n");
            $missing = $tmp->path . '/missing';

            self::assertSame(
                TurboPlatform::fromProcess(\PHP_OS_FAMILY, php_uname('m'), self::MUSL_MAPS, false)->describe(),
                TurboPlatform::fromRuntime($musl, $missing)->describe(),
                'readable maps decide',
            );
            self::assertSame(
                TurboPlatform::fromProcess(\PHP_OS_FAMILY, php_uname('m'), null, true)->describe(),
                TurboPlatform::fromRuntime($missing, $alpine)->describe(),
                'without maps, /etc/alpine-release decides',
            );
        } finally {
            $tmp->remove();
        }
    }

    #[Test]
    public function theProcessIsThreadSafeExactlyWhenThisPhpIs(): void
    {
        $description = TurboPlatform::fromProcess(self::LINUX, self::X86_64, self::GLIBC_MAPS, false)->describe();

        self::assertSame((bool)\PHP_ZTS, str_ends_with($description, ' ZTS'));
        self::assertStringContainsString(', PHP ' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, $description);
    }

    #[Test]
    #[DataProvider('linuxProcesses')]
    public function aLinuxProcessIsMuslOnlyWhenTheMuslLoaderIsMappedIntoIt(?string $selfMaps, bool $hasAlpineRelease, string $directory, string $libc): void
    {
        $platform = TurboPlatform::fromProcess(self::LINUX, self::X86_64, $selfMaps, $hasAlpineRelease);

        self::assertSame($directory . '/' . self::CORE, $platform->corePath());
        self::assertStringContainsString(', libc ' . $libc . ',', $platform->describe());
    }

    /** @return iterable<string, array{?string, bool, string, string}> */
    public static function linuxProcesses(): iterable
    {
        yield 'glibc process on a host that also has musl-tools' => [self::GLIBC_MAPS, false, self::LINUX_GNU_X86_64, self::GNU];
        yield 'musl process' => [self::MUSL_MAPS, false, self::LINUX_MUSL_X86_64, self::MUSL];
        yield 'the maps outrank /etc/alpine-release' => [self::GLIBC_MAPS, true, self::LINUX_GNU_X86_64, self::GNU];
        yield 'no readable maps on Alpine' => [null, true, self::LINUX_MUSL_X86_64, self::MUSL];
        yield 'no readable maps elsewhere' => [null, false, self::LINUX_GNU_X86_64, self::GNU];
    }

    #[Test]
    public function aProcessOutsideLinuxHasNoLibcWhateverItsMapsSay(): void
    {
        $platform = TurboPlatform::fromProcess(self::DARWIN, self::ARM64, self::MUSL_MAPS, true);

        self::assertSame('macos-arm64/' . self::CORE, $platform->corePath());
        self::assertStringNotContainsString('libc', $platform->describe());
    }

    #[Test]
    public function theProcessKeepsTheMachineItWasGiven(): void
    {
        self::assertSame('linux-gnu-arm64/' . self::CORE, TurboPlatform::fromProcess(self::LINUX, 'aarch64', self::GLIBC_MAPS, false)->corePath());
    }
}
