<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Turbo;

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
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "a published file is replaced by rewriting it in place" (PR #83's
 * verification).
 *
 * A running PHPStan has the Turbo binary mapped. Rewriting that file's bytes, which is what
 * rename() does when the staged copy is on another filesystem (it copies into the existing inode),
 * crashes the running process with SIGSEGV or SIGBUS; replacing it by a same-filesystem rename
 * leaves the old inode to the processes that still use it. A hard link to the old binary shows
 * which happened: after an atomic replace it still holds the old bytes.
 *
 * The library is put on /dev/shm, a different filesystem from the system temp directory on Linux,
 * because the defect only shows across filesystems, as in a Docker container with the project
 * bind-mounted.
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
#[Large]
final class TurboInstallerReplacesTheBinaryAtomicallyTest extends TestCase
{
    private const string VERSION = '2.3.0';

    private const string ASSET = 'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip';

    private const string BINARY = 'vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string SECOND_FILESYSTEM = '/dev/shm';

    private TempDir $library;

    private TempDir $zips;

    protected function setUp(): void
    {
        $this->library = TempDir::createUnder(self::SECOND_FILESYSTEM, 'turbo-atomic');
        $this->zips    = TempDir::create('turbo-atomic-zips');
        $this->library->write('phive.xml', \sprintf('<phive><phar name="phpstan" version="^2" installed="%s"/></phive>', self::VERSION));
    }

    protected function tearDown(): void
    {
        $this->library->remove();
        $this->zips->remove();
    }

    #[Test]
    public function aReinstallReplacesTheBinaryWithoutRewritingTheOneInUse(): void
    {
        self::assertNotSame(
            $this->device(self::SECOND_FILESYSTEM),
            $this->device(sys_get_temp_dir()),
            'this test needs /dev/shm and the system temp directory on different filesystems',
        );

        $old = $this->zip("\x7fELF the binary a running PHPStan has mapped");
        $this->writeManifest($old);
        self::assertSame(0, $this->install($old));

        $inUse = $this->library->path . '/in-use.so';
        \Safe\link($this->library->path . '/' . self::BINARY, $inUse);

        $new = $this->zip("\x7fELF the next release's binary");
        $this->writeManifest($new);
        self::assertSame(0, $this->install($new));

        self::assertSame("\x7fELF the next release's binary", $this->library->read(self::BINARY));
        self::assertSame(
            "\x7fELF the binary a running PHPStan has mapped",
            \Safe\file_get_contents($inUse),
            'the old binary was rewritten in place, so a PHPStan that has it mapped would crash',
        );
    }

    private function device(string $path): int
    {
        $stat = stat($path);
        self::assertIsArray($stat, 'cannot stat ' . $path);

        return $stat['dev'];
    }

    private function install(string $zip): int
    {
        $errors    = new BufferedOutput();
        $installer = new TurboInstaller(
            new FakeTurboReleaseSource(null, [self::ASSET => $zip]),
            new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false),
            false,
            new BufferedOutput(),
            $errors,
        );

        $exit = $installer->run($this->library->path, TurboInstaller::MODE_INSTALL);
        self::assertSame('', $errors->fetch());

        return $exit;
    }

    private function zip(string $binary): string
    {
        $path = $this->zips->path . '/' . bin2hex(random_bytes(4)) . '-' . self::ASSET;
        new PharData($path, 0, null, Phar::ZIP)->addFromString('phpstan_turbo.so', $binary);

        return \Safe\file_get_contents($path);
    }

    private function writeManifest(string $zip): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, [self::ASSET => hash('sha256', $zip)])->toJson());
    }
}
