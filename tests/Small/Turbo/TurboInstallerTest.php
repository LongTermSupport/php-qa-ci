<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\Tests\Support\FakeTurboReleaseSource;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Turbo\Dto\TurboDecisionDto;
use LTS\PHPQA\Turbo\TurboInstallDecider;
use LTS\PHPQA\Turbo\TurboInstaller;
use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The installer against a release source that serves a zip built here, so every branch runs
 * without the network: the binary lands where phpstan.phar looks, a digest that differs places
 * nothing, and an update regenerates the manifest only in a maintainer environment.
 *
 * @internal
 */
#[CoversClass(TurboInstaller::class)]
#[UsesClass(TemporaryDirectory::class)]
#[UsesClass(PhpstanDocsCatalogue::class)]
#[UsesClass(TurboDecisionDto::class)]
#[UsesClass(TurboInstallDecider::class)]
#[UsesClass(TurboManifest::class)]
#[UsesClass(TurboPlatform::class)]
#[Small]
final class TurboInstallerTest extends TestCase
{
    private const string VERSION = '2.3.0';

    private const string ASSET = 'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip';

    private const string BINARY = 'vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string SO_BYTES = "\x7fELF the turbo binary";

    private TempDir $library;

    private TempDir $zips;

    private BufferedOutput $output;

    private BufferedOutput $errors;

    protected function setUp(): void
    {
        $this->library = TempDir::create('turbo-library');
        $this->zips    = TempDir::create('turbo-zips');
        $this->output  = new BufferedOutput();
        $this->errors  = new BufferedOutput();
        $this->library->write('phive.xml', \sprintf('<phive><phar name="phpstan" version="^2" installed="%s"/></phive>', self::VERSION));
    }

    protected function tearDown(): void
    {
        $this->library->remove();
        $this->zips->remove();
    }

    #[Test]
    public function anInstallPlacesTheVerifiedBinaryWherePhpstanLooks(): void
    {
        $zip    = $this->zip();
        $source = $this->source(assets: [self::ASSET => $zip]);
        $this->writeManifest([self::ASSET => $this->sha256($zip)]);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL), $this->errors->fetch());

        self::assertSame(self::SO_BYTES, $this->library->read(self::BINARY));
        self::assertSame(0o644, \Safe\fileperms($this->library->path . '/' . self::BINARY) & 0o777, 'a shared library nobody else may rewrite');
        self::assertStringContainsString('Installing PHPStan Turbo 2.3.0', $this->output->fetch());
    }

    #[Test]
    public function aSecondInstallFindsTheBinaryReadyAndDownloadsNothing(): void
    {
        $zip    = $this->zip();
        $source = $this->source(assets: [self::ASSET => $zip]);
        $this->writeManifest([self::ASSET => $this->sha256($zip)]);
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        $this->output->fetch();

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(1, $source->downloads, 'the second install downloaded again');
        self::assertSame('', $this->output->fetch(), 'an install that is simply fine says nothing');
    }

    /** The stamp is beside the binary, so a deleted binary is fetched again rather than trusted. */
    #[Test]
    public function aDeletedBinaryIsFetchedAgain(): void
    {
        $zip    = $this->zip();
        $source = $this->source(assets: [self::ASSET => $zip]);
        $this->writeManifest([self::ASSET => $this->sha256($zip)]);
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        \Safe\unlink($this->library->path . '/' . self::BINARY);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(self::SO_BYTES, $this->library->read(self::BINARY));
    }

    #[Test]
    public function aDownloadWhoseDigestDiffersFailsAndPlacesNothing(): void
    {
        $source = $this->source(assets: [self::ASSET => $this->zip()]);
        $this->writeManifest([self::ASSET => str_repeat('0', 64)]);

        self::assertSame(1, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertFileDoesNotExist($this->library->path . '/' . self::BINARY);
        self::assertStringContainsString('SHA-256', $this->errors->fetch());
    }

    /** Offline, an install still succeeds: PHPStan runs without Turbo, and says why on the next install. */
    #[Test]
    public function aFailedDownloadWarnsAndLetsTheInstallSucceed(): void
    {
        $this->writeManifest([self::ASSET => str_repeat('0', 64)]);

        self::assertSame(0, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertFileDoesNotExist($this->library->path . '/' . self::BINARY);
        self::assertStringContainsString('without Turbo', $this->errors->fetch());
    }

    #[Test]
    public function aHostUpstreamBuildsNothingForSucceedsSayingSo(): void
    {
        $this->writeManifest([self::ASSET => str_repeat('0', 64)]);
        $windows = new TurboPlatform('Windows', 'AMD64', '', '8.5', false);

        self::assertSame(0, $this->installer($this->source(), $windows)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertStringContainsString('not built for Windows', $this->output->fetch());
    }

    #[Test]
    public function aManifestForAnotherPharVersionFailsTheInstall(): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest('2.2.16', [])->toJson());

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertStringContainsString('2.2.16', $this->errors->fetch());
    }

    #[Test]
    public function anUnreadableManifestFailsTheInstall(): void
    {
        $this->library->write(TurboManifest::PATH, '{"assets": {}}');

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertStringContainsString(TurboManifest::PATH, $this->errors->fetch());
    }

    #[Test]
    public function anUpdateInAMaintainerEnvironmentRegeneratesTheManifestFromTheRelease(): void
    {
        $zip     = $this->zip();
        $release = $this->releaseJson([self::ASSET => $this->sha256($zip)]);
        $source  = $this->source(release: $release, assets: [self::ASSET => $zip]);
        $this->writeManifest([]);

        self::assertSame(0, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE), $this->errors->fetch());

        self::assertSame([self::ASSET => $this->sha256($zip)], TurboManifest::fromJson($this->library->read(TurboManifest::PATH))->assets);
        self::assertSame(self::SO_BYTES, $this->library->read(self::BINARY));
        self::assertStringContainsString('Commit ' . TurboManifest::PATH, $this->output->fetch());
    }

    /** A consumer's update never asks GitHub for a manifest: the committed one is the pin. */
    #[Test]
    public function anUpdateOutsideAMaintainerEnvironmentLeavesTheManifestAlone(): void
    {
        $zip    = $this->zip();
        $source = $this->source(release: $this->releaseJson([]), assets: [self::ASSET => $zip]);
        $this->writeManifest([self::ASSET => $this->sha256($zip)]);
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame(0, $source->releaseLookups);
        self::assertSame($before, $this->library->read(TurboManifest::PATH));
    }

    #[Test]
    public function aReleaseForAnotherTagFailsTheUpdateAndKeepsTheManifest(): void
    {
        $source = $this->source(release: \Safe\json_encode(['tag_name' => '2.3.1', 'assets' => []]));
        $this->writeManifest([self::ASSET => str_repeat('0', 64)]);
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(1, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame($before, $this->library->read(TurboManifest::PATH));
        self::assertStringContainsString('2.3.1', $this->errors->fetch());
    }

    private function installer(FakeTurboReleaseSource $source, ?TurboPlatform $platform = null, bool $maintainer = false): TurboInstaller
    {
        return new TurboInstaller(
            $source,
            $platform ?? new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false),
            $maintainer,
            $this->output,
            $this->errors,
        );
    }

    /** @param array<string, string> $assets asset name => zip bytes */
    private function source(?string $release = null, array $assets = []): FakeTurboReleaseSource
    {
        return new FakeTurboReleaseSource($release, $assets);
    }

    /** A release zip as upstream builds it: one `phpstan_turbo.so` at the root. */
    private function zip(): string
    {
        $path = $this->zips->path . '/' . self::ASSET;
        new PharData($path, 0, null, Phar::ZIP)->addFromString('phpstan_turbo.so', self::SO_BYTES);

        return \Safe\file_get_contents($path);
    }

    /** @param array<string, string> $assets */
    private function writeManifest(array $assets): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, $assets)->toJson());
    }

    /** The digest the manifest pins for a zip. */
    private function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** @param array<string, string> $digests asset name => hex SHA-256 */
    private function releaseJson(array $digests): string
    {
        $assets = [];
        foreach ($digests as $name => $digest) {
            $assets[] = ['name' => $name, 'digest' => 'sha256:' . $digest];
        }

        return \Safe\json_encode(['tag_name' => self::VERSION, 'assets' => $assets]);
    }
}
