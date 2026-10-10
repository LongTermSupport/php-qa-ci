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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The installer against a source that serves the files of phpstan/phpstan's turbo-ext/ from
 * memory, so every branch runs without the network: the binary and its core land where
 * phpstan.phar looks, a digest that differs places nothing, and an update regenerates the manifest
 * only in a maintainer environment.
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
    private const string VERSION = '2.3.1';

    private const string BINARY_PATH = 'linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string CORE_PATH = 'linux-gnu-x86_64/phpstan_turbo_core.so';

    private const string BINARY = 'vendor-phar/turbo-ext/' . self::BINARY_PATH;

    private const string CORE = 'vendor-phar/turbo-ext/' . self::CORE_PATH;

    private const string BINARY_BYTES = "\x7fELF the thin turbo binary";

    private const string CORE_BYTES = "\x7fELF the turbo core";

    private TempDir $library;

    private BufferedOutput $output;

    private BufferedOutput $errors;

    protected function setUp(): void
    {
        $this->library = TempDir::create('turbo-library');
        $this->output  = new BufferedOutput();
        $this->errors  = new BufferedOutput();
        $this->library->write('phive.xml', \sprintf('<phive><phar name="phpstan" version="^2" installed="%s"/></phive>', self::VERSION));
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    /** The phar refuses to load a binary that has no core beside it, so both must land. */
    #[Test]
    public function anInstallPlacesTheVerifiedBinaryAndItsCoreWherePhpstanLooks(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL), $this->errors->fetch());

        self::assertSame(self::BINARY_BYTES, $this->library->read(self::BINARY));
        self::assertSame(self::CORE_BYTES, $this->library->read(self::CORE));
        self::assertSame(0o644, \Safe\fileperms($this->library->path . '/' . self::BINARY) & 0o777, 'a shared library nobody else may rewrite');
        self::assertSame(0o644, \Safe\fileperms($this->library->path . '/' . self::CORE) & 0o777, 'a shared library nobody else may rewrite');
        self::assertStringContainsString('Installing PHPStan Turbo 2.3.1', $this->output->fetch());
    }

    #[Test]
    public function aSecondInstallFindsTheFilesReadyAndDownloadsNothing(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        $this->output->fetch();

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(2, $source->downloads, 'the second install downloaded again');
        self::assertSame('', $this->output->fetch(), 'an install that is simply fine says nothing');
    }

    /** The stamp is beside the binary, so a deleted binary is fetched again rather than trusted. */
    #[Test]
    public function aDeletedBinaryIsFetchedAgain(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        \Safe\unlink($this->library->path . '/' . self::BINARY);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(self::BINARY_BYTES, $this->library->read(self::BINARY));
    }

    #[Test]
    public function aDeletedCoreIsFetchedAgain(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        \Safe\unlink($this->library->path . '/' . self::CORE);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(self::CORE_BYTES, $this->library->read(self::CORE));
    }

    #[Test]
    public function aDownloadWhoseDigestDiffersFailsAndPlacesNothing(): void
    {
        $this->writeManifest([...$this->digests(), self::BINARY_PATH => str_repeat('0', 64)]);

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertFileDoesNotExist($this->library->path . '/' . self::BINARY);
        self::assertFileDoesNotExist($this->library->path . '/' . self::CORE, 'a verified core must not be placed beside a binary that failed');
        self::assertStringContainsString('SHA-256', $this->errors->fetch());
    }

    /** Offline, an install still succeeds: PHPStan runs without Turbo, and says why on the next install. */
    #[Test]
    public function aFailedDownloadWarnsAndLetsTheInstallSucceed(): void
    {
        $this->writeManifest($this->digests());

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource(null, []))->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertFileDoesNotExist($this->library->path . '/' . self::BINARY);
        self::assertStringContainsString('without Turbo', $this->errors->fetch());
    }

    #[Test]
    public function aHostUpstreamBuildsNothingForSucceedsSayingSo(): void
    {
        $this->writeManifest($this->digests());
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
        $this->library->write(TurboManifest::PATH, '{"files": {}}');

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertStringContainsString(TurboManifest::PATH, $this->errors->fetch());
    }

    #[Test]
    public function anUpdateInAMaintainerEnvironmentRegeneratesTheManifestFromTheRepositoryTree(): void
    {
        $source = $this->source(tree: $this->treeJson());
        $this->writeManifest([]);

        self::assertSame(0, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE), $this->errors->fetch());

        self::assertSame($this->digests(), TurboManifest::fromJson($this->library->read(TurboManifest::PATH))->files);
        self::assertSame(self::BINARY_BYTES, $this->library->read(self::BINARY));
        self::assertSame(self::CORE_BYTES, $this->library->read(self::CORE));
        self::assertStringContainsString('Commit ' . TurboManifest::PATH, $this->output->fetch());
    }

    /** A consumer's update never asks GitHub for a manifest: the committed one is the pin. */
    #[Test]
    public function anUpdateOutsideAMaintainerEnvironmentLeavesTheManifestAlone(): void
    {
        $source = $this->source(tree: $this->treeJson());
        $this->writeManifest($this->digests());
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame(0, $source->treeLookups);
        self::assertSame($before, $this->library->read(TurboManifest::PATH));
    }

    #[Test]
    public function aTreeThatCannotBeReadKeepsTheManifestAndWarns(): void
    {
        $this->writeManifest($this->digests());
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource(null, []), maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame($before, $this->library->read(TurboManifest::PATH));
        self::assertStringContainsString('left as it is', $this->errors->fetch());
    }

    #[Test]
    public function aTruncatedTreeFailsTheUpdateAndKeepsTheManifest(): void
    {
        $source = $this->source(tree: \Safe\json_encode(['truncated' => true, 'tree' => []]));
        $this->writeManifest($this->digests());
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(1, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame($before, $this->library->read(TurboManifest::PATH));
        self::assertStringContainsString('truncated', $this->errors->fetch());
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

    private function source(?string $tree = null): FakeTurboReleaseSource
    {
        return new FakeTurboReleaseSource($tree, [self::CORE_PATH => self::CORE_BYTES, self::BINARY_PATH => self::BINARY_BYTES]);
    }

    /** @param array<string, string> $files path under turbo-ext/ => hex SHA-256 */
    private function writeManifest(array $files): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, $files)->toJson());
    }

    /** @return array<string, string> the digests of the two files, sorted as the manifest sorts them */
    private function digests(): array
    {
        return [self::BINARY_PATH => hash('sha256', self::BINARY_BYTES), self::CORE_PATH => hash('sha256', self::CORE_BYTES)];
    }

    /** What GitHub's tree API says phpstan/phpstan holds under turbo-ext/ at the tag. */
    private function treeJson(): string
    {
        $entries = [];
        foreach (['.version', self::BINARY_PATH, self::CORE_PATH] as $path) {
            $entries[] = ['path' => 'turbo-ext/' . $path, 'type' => 'blob'];
        }

        return \Safe\json_encode(['truncated' => false, 'tree' => $entries]);
    }
}
