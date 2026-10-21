<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\Tests\Support\FakeTurboReleaseSource;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\UnremovableTree;
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
#[UsesClass(\LTS\PHPQA\Filesystem\DirectoryTree::class)]
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

    private const string STAMP = self::BINARY . '.source';

    private const string DIGEST = 'sha256';

    private const string DOWNLOAD_WARNING = 'WARNING: could not download turbo-ext/';

    private const string WITHOUT_TURBO = "; PHPStan runs without Turbo until the next composer install.\n";

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
        self::assertSame(\sprintf("ERROR: turbo-ext/%s has SHA-256 %s, but %s pins %s. Nothing was installed.\n", self::BINARY_PATH, hash(self::DIGEST, self::BINARY_BYTES), TurboManifest::PATH, str_repeat('0', 64)), $this->errors->fetch());
    }

    /** Offline, an install still succeeds: PHPStan runs without Turbo, and says why on the next install. */
    #[Test]
    public function aFailedDownloadWarnsAndLetsTheInstallSucceed(): void
    {
        $this->writeManifest($this->digests());

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource(null, []))->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertFileDoesNotExist($this->library->path . '/' . self::BINARY);
        self::assertSame(self::DOWNLOAD_WARNING . self::CORE_PATH . self::WITHOUT_TURBO, $this->errors->fetch());
    }

    /**
     * phpstan.phar loads whatever binary it finds beside a core, stamped or not, so an earlier pair
     * left in place is loaded on the next run: the warning is true only once the binary is gone.
     */
    #[Test]
    public function aFailedDownloadRemovesAnEarlierBinarySoPhpstanRunsWithoutTurbo(): void
    {
        $this->writeManifest($this->digests());
        $this->library->write(self::BINARY, 'a binary an earlier release installed');
        $this->library->write(self::CORE, 'a core an earlier release installed');
        $this->library->write(self::STAMP, "the stamp of an earlier release\n");

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource(null, []))->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(self::DOWNLOAD_WARNING . self::CORE_PATH . self::WITHOUT_TURBO, $this->errors->fetch());
        self::assertSame([basename(self::CORE_PATH)], $this->platformDirectory(), 'the earlier binary and its stamp are still there, so PHPStan still loads it');
    }

    /** A killed install can place the pair and not the stamp; nothing then vouches for the binary. */
    #[Test]
    public function aFailedDownloadRemovesABinaryNoStampVouchesFor(): void
    {
        $this->writeManifest($this->digests());
        $this->library->write(self::BINARY, 'a binary a killed install placed');
        $this->library->write(self::CORE, 'the core a killed install placed');

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource(null, []))->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame([basename(self::CORE_PATH)], $this->platformDirectory(), 'the unstamped binary is still there, so PHPStan still loads it');
    }

    #[Test]
    public function aDownloadWhoseDigestDiffersRemovesAnEarlierBinary(): void
    {
        $this->writeManifest([...$this->digests(), self::BINARY_PATH => str_repeat('0', 64)]);
        $this->library->write(self::BINARY, 'a binary an earlier release installed');
        $this->library->write(self::CORE, 'a core an earlier release installed');

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame([basename(self::CORE_PATH)], $this->platformDirectory(), 'the earlier binary is still there, so PHPStan still loads it');
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

        self::assertSame("ERROR: vendor-phar/turbo-ext.json is for PHPStan 2.2.16 but the shipped phpstan.phar is 2.3.1; a binary from another release does not load. Run scripts/tool-install.bash update.\n", $this->errors->fetch());
    }

    #[Test]
    public function anUnreadableManifestFailsTheInstall(): void
    {
        $this->library->write(TurboManifest::PATH, '{"files": {}}');

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame("ERROR: vendor-phar/turbo-ext.json has no \"phpstan\" version. Restore it from git, or run scripts/tool-install.bash update.\n", $this->errors->fetch());
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
        self::assertSame("WARNING: could not read the phpstan/phpstan 2.3.1 tree; vendor-phar/turbo-ext.json left as it is.\n"
            . self::DOWNLOAD_WARNING . self::CORE_PATH . self::WITHOUT_TURBO, $this->errors->fetch());
    }

    #[Test]
    public function aTruncatedTreeFailsTheUpdateAndKeepsTheManifest(): void
    {
        $source = $this->source(tree: \Safe\json_encode(['truncated' => true, 'tree' => []]));
        $this->writeManifest($this->digests());
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(1, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame($before, $this->library->read(TurboManifest::PATH));
        self::assertSame("ERROR: the phpstan/phpstan tree response is truncated, so a manifest from it would pin only some of the Turbo files.\n", $this->errors->fetch());
    }

    #[Test]
    public function anInstallStampsTheBinaryWithWhatItWasInstalledFromAndLeavesNoStagingBehind(): void
    {
        $this->writeManifest($this->digests());

        self::assertSame(0, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame($this->stamp(), $this->library->read(self::STAMP));
        self::assertSame(
            ['phpstan_turbo-8.5.so', 'phpstan_turbo-8.5.so.source', 'phpstan_turbo_core.so'],
            $this->platformDirectory(),
        );
    }

    /** Without the stamp nothing says which files are installed, so they are fetched again. */
    #[Test]
    public function aDeletedStampIsFetchedAgain(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        \Safe\unlink($this->library->path . '/' . self::STAMP);

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(4, $source->downloads);
        self::assertSame($this->stamp(), $this->library->read(self::STAMP));
    }

    #[Test]
    public function anUpdateWithEverythingInPlaceSaysSo(): void
    {
        $source = $this->source();
        $this->writeManifest($this->digests());
        $this->installer($source)->run($this->library->path, TurboInstaller::MODE_INSTALL);
        $this->output->fetch();

        self::assertSame(0, $this->installer($source)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame("PHPStan Turbo 2.3.1 present for Linux x86_64, libc gnu, PHP 8.5.\n", $this->output->fetch());
        self::assertSame('', $this->errors->fetch());
    }

    #[Test]
    public function aFileThatCannotBePlacedFailsTheInstallAndLeavesNoStagingBehind(): void
    {
        $this->writeManifest($this->digests());
        $this->library->mkdir(self::BINARY . '/in-the-way');

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $exitCode = $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL);
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $exitCode);
        self::assertCount(1, $warnings, 'the rename onto the directory in the way');
        self::assertStringStartsWith('ERROR: could not install the Turbo files: ', $this->errors->fetch());
        // The core goes first, so what is left is a core with no binary to load it.
        self::assertSame(['phpstan_turbo-8.5.so', 'phpstan_turbo_core.so'], $this->platformDirectory());
    }

    /** A staging directory that cannot be made beside the binary fails the install as any other step does. */
    #[Test]
    public function aStagingDirectoryThatCannotBeMadeFailsTheInstallSayingWhy(): void
    {
        $this->writeManifest($this->digests());
        $this->library->write(\dirname(self::BINARY), 'a file where the platform directory belongs');

        self::assertSame(1, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));

        self::assertSame(
            \sprintf("ERROR: could not install the Turbo files: Cannot create a staging directory in %s: mkdir(): File exists\n", \dirname($this->library->path . '/' . self::BINARY)),
            $this->errors->fetch(),
        );
    }

    /** A staging leftover the install cannot remove is named in a warning; the install goes on. */
    #[Test]
    public function aStagingLeftoverThatCannotBeRemovedIsNamedAndTheInstallSucceeds(): void
    {
        $this->writeManifest($this->digests());
        $leftover = \dirname($this->library->path . '/' . self::BINARY) . '/.phpqa-turbo0123456789abcdef';
        UnremovableTree::plant($leftover);
        \Safe\touch($leftover, time() - 2 * 3600);

        try {
            self::assertSame(0, $this->installer($this->source())->run($this->library->path, TurboInstaller::MODE_INSTALL));
            $kept = array_values(array_filter(\Safe\glob(\dirname($leftover) . '/.phpqa-turbo*'), \is_string(...)));
        } finally {
            foreach (array_filter(\Safe\glob(\dirname($leftover) . '/.phpqa-turbo*'), \is_string(...)) as $stuck) {
                UnremovableTree::remove($stuck);
            }
        }

        self::assertSame(self::BINARY_BYTES, $this->library->read(self::BINARY));
        self::assertCount(1, $kept);
        self::assertStringStartsWith(\sprintf('WARNING: could not remove %s, a staging directory a killed install left: ', $kept[0]), $this->errors->fetch());
    }

    /** A maintainer offline still gets an install: the committed manifest stands. */
    #[Test]
    public function anUpdateThatCannotDownloadATreeFileKeepsTheManifestAndWarns(): void
    {
        $this->writeManifest($this->digests());
        $before = $this->library->read(TurboManifest::PATH);

        self::assertSame(0, $this->installer(new FakeTurboReleaseSource($this->treeJson(), []), maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE));

        self::assertSame($before, $this->library->read(TurboManifest::PATH));
        self::assertSame(
            self::DOWNLOAD_WARNING . self::BINARY_PATH . "; vendor-phar/turbo-ext.json left as it is.\n"
            . self::DOWNLOAD_WARNING . self::CORE_PATH . self::WITHOUT_TURBO,
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function anUpdateWhoseTreeMatchesTheManifestRewritesNothing(): void
    {
        $this->writeManifest($this->digests());
        $source = $this->source(tree: $this->treeJson(self::BINARY_PATH, self::CORE_PATH));

        self::assertSame(0, $this->installer($source, maintainer: true)->run($this->library->path, TurboInstaller::MODE_UPDATE), $this->errors->fetch());

        self::assertSame("Installing PHPStan Turbo 2.3.1 for Linux x86_64, libc gnu, PHP 8.5...\n", $this->output->fetch(), 'a manifest that is current is not rewritten');
    }

    /** The stamp the installer writes beside the binary: the files in the order placed, the core first. */
    private function stamp(): string
    {
        return TurboInstallDecider::stamp([self::CORE_PATH => hash(self::DIGEST, self::CORE_BYTES), self::BINARY_PATH => hash(self::DIGEST, self::BINARY_BYTES)]) . "\n";
    }

    /** @return list<string> the entries of the directory the binary is in */
    private function platformDirectory(): array
    {
        $entries = array_values(array_filter(\Safe\scandir(\dirname($this->library->path . '/' . self::BINARY)), is_string(...)));

        return array_values(array_diff($entries, ['.', '..']));
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
        return [self::BINARY_PATH => hash(self::DIGEST, self::BINARY_BYTES), self::CORE_PATH => hash(self::DIGEST, self::CORE_BYTES)];
    }

    /** What GitHub's tree API says phpstan/phpstan holds under turbo-ext/ at the tag. */
    private function treeJson(string ...$paths): string
    {
        $entries = [];
        foreach ([] === $paths ? ['.version', self::BINARY_PATH, self::CORE_PATH] : $paths as $path) {
            $entries[] = ['path' => 'turbo-ext/' . $path, 'type' => 'blob'];
        }

        return \Safe\json_encode(['truncated' => false, 'tree' => $entries]);
    }
}
