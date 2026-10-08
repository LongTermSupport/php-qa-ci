<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The names here are upstream's: the asset names of the phpstan/turbo-ext release, and the
 * directory and file names under phpstan/phpstan's turbo-ext/, which is where the phar looks.
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

    private const string DARWIN = 'Darwin';

    private const string VERSION = '2.3.0';

    private const string LINUX = 'Linux';

    private const string X86_64 = 'x86_64';

    private const string GNU = 'gnu';

    #[Test]
    #[DataProvider('supportedHosts')]
    public function aSupportedHostNamesItsAssetAndWherePhpstanLooks(TurboPlatform $platform, string $asset, string $directory, string $binary): void
    {
        self::assertTrue($platform->isSupported());
        self::assertSame($asset, $platform->assetName(self::VERSION));
        self::assertSame($directory . '/' . $binary, $platform->binaryPath());
    }

    /** @return iterable<string, array{TurboPlatform, string, string, string}> */
    public static function supportedHosts(): iterable
    {
        yield 'linux glibc x86_64' => [
            new TurboPlatform(self::LINUX, self::X86_64, self::GNU, self::PHP_MINOR, false),
            'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip',
            self::LINUX_GNU_X86_64,
            self::BINARY_85,
        ];
        yield 'linux glibc aarch64 is arm64' => [
            new TurboPlatform(self::LINUX, 'aarch64', self::GNU, self::PHP_MINOR, false),
            'php_phpstan_turbo-2.3.0_php8.5-arm64-linux-glibc.zip',
            'linux-gnu-arm64',
            self::BINARY_85,
        ];
        yield 'linux musl x86_64' => [
            new TurboPlatform(self::LINUX, self::X86_64, 'musl', self::PHP_MINOR, false),
            'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-musl.zip',
            'linux-musl-x86_64',
            self::BINARY_85,
        ];
        yield 'linux zts' => [
            new TurboPlatform(self::LINUX, self::X86_64, self::GNU, '8.6', true),
            'php_phpstan_turbo-2.3.0_php8.6-x86_64-linux-glibc-zts.zip',
            self::LINUX_GNU_X86_64,
            'phpstan_turbo-8.6-zts.so',
        ];
        yield 'amd64 is x86_64' => [
            new TurboPlatform(self::LINUX, 'amd64', self::GNU, self::PHP_MINOR, false),
            'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip',
            self::LINUX_GNU_X86_64,
            self::BINARY_85,
        ];
        yield 'macos arm64' => [
            new TurboPlatform(self::DARWIN, 'arm64', '', self::PHP_MINOR, false),
            'php_phpstan_turbo-2.3.0_php8.5-arm64-darwin-bsdlibc.zip',
            'macos-arm64',
            self::BINARY_85,
        ];
    }

    #[Test]
    #[DataProvider('unsupportedHosts')]
    public function anUnsupportedHostHasNoAssetAndNoPath(TurboPlatform $platform): void
    {
        self::assertFalse($platform->isSupported());
        self::assertNull($platform->assetName(self::VERSION));
        self::assertNull($platform->binaryPath());
    }

    /** @return iterable<string, array{TurboPlatform}> */
    public static function unsupportedHosts(): iterable
    {
        yield 'windows' => [new TurboPlatform('Windows', 'AMD64', '', self::PHP_MINOR, false)];
        yield 'intel mac' => [new TurboPlatform(self::DARWIN, self::X86_64, '', self::PHP_MINOR, false)];
        yield 'linux on an unknown cpu' => [new TurboPlatform(self::LINUX, 'riscv64', self::GNU, self::PHP_MINOR, false)];
        yield 'linux with an unknown libc' => [new TurboPlatform(self::LINUX, self::X86_64, '', self::PHP_MINOR, false)];
        yield 'zts mac' => [new TurboPlatform(self::DARWIN, 'arm64', '', self::PHP_MINOR, true)];
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
}
