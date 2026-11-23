<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use Iterator;
use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pin, where the fetched bundle lives, which host it runs on, and how the version it reports
 * is read: one place, because the lane and the installer must agree on every answer.
 *
 * @internal
 */
#[CoversClass(ActionsQaBinary::class)]
#[Small]
final class ActionsQaBinaryTest extends TestCase
{
    private const string LIBRARY = '/opt/library';

    private const string LINUX = 'Linux';

    private const string X86_64 = 'x86_64';

    private const string ARM64 = 'arm64';

    private const string AMD64_BUNDLE = 'linux_amd64';

    #[Test]
    public function thePinIsAReleaseVersionAndTheInstallScriptDigestIsASha256(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ActionsQaBinary::VERSION);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ActionsQaBinary::INSTALL_SCRIPT_SHA256);
    }

    #[Test]
    public function theBundleIsInstalledUnderTheUntrackedVendorBinArea(): void
    {
        self::assertSame(self::LIBRARY . '/vendor-bin/actions-qa-ci', ActionsQaBinary::installDir(self::LIBRARY));
        self::assertSame(self::LIBRARY . '/vendor-bin/actions-qa-ci/bin/actions-qa', ActionsQaBinary::path(self::LIBRARY));
    }

    #[Test]
    public function theInstallScriptIsTheOneThePinnedReleasePublishes(): void
    {
        self::assertSame(
            'https://github.com/LongTermSupport/actions-qa-ci/releases/download/v' . ActionsQaBinary::VERSION . '/install.sh',
            ActionsQaBinary::installScriptUrl(),
        );
    }

    /** The tarball name install.sh looks for, which an offline host keeps beside it. */
    #[Test]
    public function theBundleAssetIsNamedForTheReleaseAndThePlatform(): void
    {
        self::assertSame('actions-qa-ci_' . ActionsQaBinary::VERSION . '_linux_amd64.tar.gz', ActionsQaBinary::bundleAsset(ActionsQaBinary::PLATFORM_AMD64));
    }

    #[Test]
    public function theInstallCommandIsTheEntryPointInTheLibrary(): void
    {
        self::assertSame('php ' . self::LIBRARY . '/bin/actions-qa-install', ActionsQaBinary::installCommand(self::LIBRARY));
    }

    #[Test]
    #[DataProvider('hostsWithABuild')]
    public function aLinuxHostMapsToTheReleasesPlatformName(string $machine, string $platform): void
    {
        self::assertSame($platform, ActionsQaBinary::platformFor(self::LINUX, $machine));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function hostsWithABuild(): Iterator
    {
        yield self::X86_64 => [self::X86_64, self::AMD64_BUNDLE];
        yield 'amd64' => ['amd64', self::AMD64_BUNDLE];
        yield 'aarch64' => ['aarch64', 'linux_arm64'];
        yield self::ARM64 => [self::ARM64, 'linux_arm64'];
        yield 'upper case' => ['X86_64', self::AMD64_BUNDLE];
    }

    #[Test]
    #[DataProvider('hostsWithNoBuild')]
    public function aHostTheReleaseBuildsNothingForHasNoPlatform(string $os, string $machine): void
    {
        self::assertNull(ActionsQaBinary::platformFor($os, $machine));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function hostsWithNoBuild(): Iterator
    {
        yield 'macOS' => ['Darwin', self::ARM64];
        yield 'Windows' => ['Windows', self::X86_64];
        yield 'riscv' => [self::LINUX, 'riscv64'];
        yield '32-bit arm' => [self::LINUX, 'armv7l'];
    }

    #[Test]
    public function theVersionReportIsReadForItsToolchainVersion(): void
    {
        self::assertSame('0.2.0', ActionsQaBinary::reportedVersion('{"method": "1.1.0", "toolchain": "0.2.0", "known-gaps": []}'));
    }

    #[Test]
    #[DataProvider('unreadableReports')]
    public function aReportThatNamesNoToolchainVersionIsNullRatherThanAGuess(string $report): void
    {
        self::assertNull(ActionsQaBinary::reportedVersion($report));
    }

    /** @return Iterator<string, array{string}> */
    public static function unreadableReports(): Iterator
    {
        yield 'empty' => [''];
        yield 'not json' => ['actions-qa (method 1.1.0, toolchain 0.2.0, 16 known gaps)'];
        yield 'no toolchain' => ['{"method": "1.1.0"}'];
        yield 'toolchain not a string' => ['{"toolchain": 2}'];
        yield 'empty toolchain' => ['{"toolchain": ""}'];
        yield 'a list' => ['["0.2.0"]'];
    }
}
