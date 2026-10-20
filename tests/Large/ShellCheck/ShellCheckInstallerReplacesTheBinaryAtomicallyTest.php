<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\ShellCheck;

use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\ShellCheck\Dto\InstallDecisionDto;
use LTS\PHPQA\ShellCheck\InstallActionEnum;
use LTS\PHPQA\ShellCheck\InstallDecider;
use LTS\PHPQA\ShellCheck\ShellCheckInstaller;
use LTS\PHPQA\Tests\Support\TempDir;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "a published file is replaced by rewriting it in place" (issue #84).
 *
 * A ShellCheck process may be running the vendored binary while a maintainer update replaces it.
 * rename() from a staging directory on another filesystem copies into the existing inode: a
 * process started during the copy runs a partial binary, an interrupted copy leaves a truncated
 * one, and a running one makes the copy fail with ETXTBSY. A same-filesystem rename swaps the
 * inode instead. A hard link to the old binary shows which happened: after an atomic replace it
 * still holds the old bytes.
 *
 * The library is put on /dev/shm, a different filesystem from the system temp directory on Linux,
 * because the defect only shows across filesystems.
 *
 * @internal
 */
#[CoversClass(ShellCheckInstaller::class)]
#[UsesClass(TemporaryDirectory::class)]
#[UsesClass(DirectoryTree::class)]
#[UsesClass(InstallDecider::class)]
#[UsesClass(InstallDecisionDto::class)]
#[UsesClass(InstallActionEnum::class)]
#[UsesClass(ShellCheckBinary::class)]
#[Large]
final class ShellCheckInstallerReplacesTheBinaryAtomicallyTest extends TestCase
{
    private const string PINNED = 'v0.10.0';

    private const string NEWEST = 'v0.11.0';

    private const string OLD_BYTES = "\x7fELF the ShellCheck a running lint is executing";

    private const string NEW_BYTES = "\x7fELF the next release's ShellCheck";

    private const string RELEASE_API = 'https://api.github.com/repos/koalaman/shellcheck/releases/latest';

    private const string NEWEST_ASSET = 'https://github.com/koalaman/shellcheck/releases/download/v0.11.0/shellcheck-v0.11.0.linux.x86_64.tar.gz';

    private const string SECOND_FILESYSTEM = '/dev/shm';

    private TempDir $library;

    protected function setUp(): void
    {
        $this->library = TempDir::createUnder(self::SECOND_FILESYSTEM, 'shellcheck-atomic');
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, self::OLD_BYTES);
        $this->library->write(ShellCheckBinary::VERSION_FILE, self::PINNED . "\n");
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function anUpdateReplacesTheBinaryWithoutRewritingTheOneInUse(): void
    {
        self::assertNotSame(
            $this->device(self::SECOND_FILESYSTEM),
            $this->device(sys_get_temp_dir()),
            'this test needs /dev/shm and the system temp directory on different filesystems',
        );

        $binary = ShellCheckBinary::path($this->library->path);
        $inUse  = $this->library->path . '/in-use-shellcheck';
        \Safe\link($binary, $inUse);

        $errors    = new BufferedOutput();
        $responses = [
            self::RELEASE_API  => \Safe\json_encode(['tag_name' => self::NEWEST]),
            self::NEWEST_ASSET => $this->releaseArchive(self::NEWEST, self::NEW_BYTES),
        ];
        $installer = new ShellCheckInstaller(
            new BufferedOutput(),
            $errors,
            static fn (string $url, string ...$headers): string => $responses[$url] ?? throw new RuntimeException('unexpected request: ' . $url),
            true,
        );

        self::assertSame(0, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame('', $errors->fetch());
        self::assertSame(self::NEW_BYTES, \Safe\file_get_contents($binary));
        self::assertSame(
            self::OLD_BYTES,
            \Safe\file_get_contents($inUse),
            'the old binary was rewritten in place, so a ShellCheck running it would execute a partial file',
        );
        self::assertSame(['shellcheck', 'shellcheck.version'], $this->entries(\dirname($binary)), 'the staging directory is removed');
    }

    private function device(string $path): int
    {
        $stat = stat($path);
        self::assertIsArray($stat, 'cannot stat ' . $path);

        return $stat['dev'];
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        return array_values(array_diff(\Safe\scandir($directory), ['.', '..']));
    }

    /** The bytes of a `.tar.gz` laid out as the published release asset is. */
    private function releaseArchive(string $version, string $binary): string
    {
        $build = TemporaryDirectory::create('phpqa-shellcheck-asset');

        try {
            $tar = new PharData($build->path . '/asset.tar');
            $tar->addFromString(\sprintf('shellcheck-%s/shellcheck', $version), $binary);
            $tar->compress(Phar::GZ);

            return \Safe\file_get_contents($build->path . '/asset.tar.gz');
        } finally {
            $build->remove();
        }
    }
}
