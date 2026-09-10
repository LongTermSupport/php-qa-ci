<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ShellCheckBinary::class)]
#[Small]
final class ShellCheckBinaryTest extends TestCase
{
    private const string BANNER = <<<'BANNER'
        ShellCheck - shell script analysis tool
        version: 0.11.0
        license: GNU General Public License, version 3
        website: https://www.shellcheck.net
        BANNER;

    #[Test]
    public function theShippedBinaryAndItsVersionPinAreBothCommitted(): void
    {
        $libraryRoot = $this->libraryRoot();

        self::assertFileExists(ShellCheckBinary::path($libraryRoot));
        self::assertFileExists($libraryRoot . '/' . ShellCheckBinary::VERSION_FILE);
    }

    #[Test]
    public function thePinIsTheVersionFilesContentWithoutItsTrailingNewline(): void
    {
        $libraryRoot = $this->libraryRoot();

        $pinned = ShellCheckBinary::pinnedVersion($libraryRoot);

        self::assertSame(trim(\Safe\file_get_contents($libraryRoot . '/' . ShellCheckBinary::VERSION_FILE)), $pinned);
        self::assertStringStartsWith('v', $pinned);
    }

    /**
     * A checkout missing the pin cannot be told apart from one running an
     * unknown build, so there is no version to fall back to.
     */
    #[Test]
    public function aCheckoutWithNoVersionFileHasNoPin(): void
    {
        self::assertNull(ShellCheckBinary::pinnedVersion('/no/such/library/root'));
    }

    /**
     * The pin is written the way the upstream release tag is (`v0.11.0`) and
     * the banner prints the bare number, so one of the two has to be
     * normalised before they can be compared at all.
     */
    #[Test]
    public function theBannersBareNumberIsNormalisedToTheReleaseTagSpelling(): void
    {
        self::assertSame('v0.11.0', ShellCheckBinary::reportedVersion(self::BANNER));
    }

    #[Test]
    #[DataProvider('unreadableBanners')]
    public function aBannerThatNamesNoVersionIsNullRatherThanAGuess(string $banner): void
    {
        self::assertNull(ShellCheckBinary::reportedVersion($banner));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableBanners(): iterable
    {
        yield 'empty' => [''];

        yield 'a shell error where the banner should be' => ["shellcheck: command not found\n"];

        yield 'the word version with nothing after it' => ["version:\n"];
    }

    /**
     * A missing binary must print the command that fixes it. The lane crashes
     * rather than skipping, so this text is the only thing standing between a
     * broken checkout and a confused reader.
     */
    #[Test]
    public function theFetchCommandNamesThePinnedReleaseAndWhereItGoes(): void
    {
        $command = ShellCheckBinary::fetchCommand('/somewhere/php-qa-ci', 'v9.9.9');

        self::assertStringContainsString('shellcheck-v9.9.9.linux.x86_64.tar.xz', $command);
        self::assertStringContainsString('/somewhere/php-qa-ci/' . ShellCheckBinary::RELATIVE_PATH, $command);
        // What goes missing is usually the whole vendor-bin/ directory, and the
        // redirect cannot create it: without the mkdir the command fails with a
        // "No such file or directory" that says nothing about the cause.
        self::assertStringContainsString('mkdir -p /somewhere/php-qa-ci/vendor-bin', $command);
    }

    /**
     * The PHARs are handed to `php`, so their mode never mattered; this one is
     * exec'd. A consumer installed from a Composer dist zip receives it
     * without the mode bit, because ZipArchive::extractTo does not restore
     * permissions — so the lane repairs it rather than failing on it.
     */
    #[Test]
    public function aBinaryThatArrivedWithoutItsModeBitIsMadeExecutable(): void
    {
        $temp = TempDir::create('phpqa-shellcheck-mode');

        try {
            $binary = $temp->write('shellcheck', "#!/bin/sh\ntrue\n");
            \Safe\chmod($binary, 0o644);
            self::assertFalse(is_executable($binary));

            self::assertNull(ShellCheckBinary::ensureExecutable($binary));
            clearstatcache(true, $binary);
            self::assertTrue(is_executable($binary));
        } finally {
            $temp->remove();
        }
    }

    #[Test]
    public function aBinaryThatIsNotThereCannotBeMadeExecutable(): void
    {
        self::assertSame('the binary is missing', ShellCheckBinary::ensureExecutable('/no/such/shellcheck'));
    }

    private function libraryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
