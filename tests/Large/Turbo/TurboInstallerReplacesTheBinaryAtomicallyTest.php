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
    private const string VERSION = '2.3.1';

    private const string BINARY_PATH = 'linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string CORE_PATH = 'linux-gnu-x86_64/phpstan_turbo_core.so';

    private const string BINARY = 'vendor-phar/turbo-ext/' . self::BINARY_PATH;

    private const string CORE = 'vendor-phar/turbo-ext/' . self::CORE_PATH;

    private const string SECOND_FILESYSTEM = '/dev/shm';

    private TempDir $library;

    protected function setUp(): void
    {
        $this->library = TempDir::createUnder(self::SECOND_FILESYSTEM, 'turbo-atomic');
        $this->library->write('phive.xml', \sprintf('<phive><phar name="phpstan" version="^2" installed="%s"/></phive>', self::VERSION));
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function aReinstallReplacesTheBinaryAndCoreWithoutRewritingTheOnesInUse(): void
    {
        self::assertNotSame(
            $this->device(self::SECOND_FILESYSTEM),
            $this->device(sys_get_temp_dir()),
            'this test needs /dev/shm and the system temp directory on different filesystems',
        );

        $old = [self::BINARY_PATH => "\x7fELF the binary a running PHPStan has mapped", self::CORE_PATH => "\x7fELF the core a running PHPStan has mapped"];
        $this->writeManifest($old);
        self::assertSame(0, $this->install($old));

        $binaryInUse = $this->library->path . '/in-use.so';
        $coreInUse   = $this->library->path . '/core-in-use.so';
        \Safe\link($this->library->path . '/' . self::BINARY, $binaryInUse);
        \Safe\link($this->library->path . '/' . self::CORE, $coreInUse);

        $new = [self::BINARY_PATH => "\x7fELF the next release's binary", self::CORE_PATH => "\x7fELF the next release's core"];
        $this->writeManifest($new);
        self::assertSame(0, $this->install($new));

        self::assertSame("\x7fELF the next release's binary", $this->library->read(self::BINARY));
        self::assertSame("\x7fELF the next release's core", $this->library->read(self::CORE));
        self::assertSame(
            $old[self::BINARY_PATH],
            \Safe\file_get_contents($binaryInUse),
            'the old binary was rewritten in place, so a PHPStan that has it mapped would crash',
        );
        self::assertSame(
            $old[self::CORE_PATH],
            \Safe\file_get_contents($coreInUse),
            'the old core was rewritten in place, so a PHPStan that has it mapped would crash',
        );
    }

    private function device(string $path): int
    {
        $stat = stat($path);
        self::assertIsArray($stat, 'cannot stat ' . $path);

        return $stat['dev'];
    }

    /** @param array<string, string> $files path under turbo-ext/ => bytes */
    private function install(array $files): int
    {
        $errors    = new BufferedOutput();
        $installer = new TurboInstaller(
            new FakeTurboReleaseSource(null, $files),
            new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false),
            false,
            new BufferedOutput(),
            $errors,
        );

        $exit = $installer->run($this->library->path, TurboInstaller::MODE_INSTALL);
        self::assertSame('', $errors->fetch());

        return $exit;
    }

    /** @param array<string, string> $files path under turbo-ext/ => bytes */
    private function writeManifest(array $files): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, array_map(static fn (string $bytes): string => hash('sha256', $bytes), $files))->toJson());
    }
}
