<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\UnremovableTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use UnexpectedValueException;

/**
 * The dead-code detector reaches PHPStan as plain files. With Turbo active PHPStan forks its
 * workers, and a second PHAR opened before the fork is read by all of them through one shared
 * archive descriptor: they parse each other's bytes ("syntax error, unexpected token") and the
 * analysis is abandoned.
 *
 * @internal
 */
#[CoversClass(DetectorUnpacker::class)]
#[UsesClass(TemporaryDirectory::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\DirectoryTree::class)]
#[Small]
final class DetectorUnpackerTest extends TestCase
{
    /** The detector PHAR php-qa-ci ships, unpacked for real. */
    private const string PHAR = __DIR__ . '/../../../../../vendor-phar/dead-code-detector.phar';

    /** A file planted in an unpacked copy, to tell a reused copy from a fresh one. */
    private const string MARKER = '/marker';

    /** Where a test plants an UnremovableTree inside an incomplete copy. */
    private const string STUCK = '/stuck';

    /** How a warning naming an entry the unpacker cannot remove starts. */
    private const string COULD_NOT_REMOVE = 'WARNING: could not remove ';

    private TempDir $cache;

    private ?string $unremovable = null;

    protected function setUp(): void
    {
        $this->cache = TempDir::create('phpqa-detector-unpacker-test');
    }

    protected function tearDown(): void
    {
        if (null !== $this->unremovable) {
            UnremovableTree::remove($this->unremovable);
        }

        $this->cache->remove();
    }

    #[Test]
    public function theDetectorIsUnpackedAsPlainFilesIntoTheCache(): void
    {
        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertStringStartsWith($this->cache->path . '/' . DetectorUnpacker::DIR . '/', $directory);
        self::assertStringNotContainsString('phar://', $directory);
        self::assertFileExists($directory . '/' . DetectorUnpacker::AUTOLOAD);
        self::assertFileExists($directory . '/' . DetectorUnpacker::RULES_NEON);
        self::assertSame(
            \Safe\file_get_contents('phar://' . \Safe\realpath(self::PHAR) . '/' . DetectorUnpacker::RULES_NEON),
            \Safe\file_get_contents($directory . '/' . DetectorUnpacker::RULES_NEON),
        );
    }

    #[Test]
    public function anUnpackedCopyOfTheSamePharIsReused(): void
    {
        $unpacker = $this->unpacker();
        $first    = $unpacker->unpack(self::PHAR, $this->cache->path);
        \Safe\file_put_contents($first . self::MARKER, 'kept');

        $second = $unpacker->unpack(self::PHAR, $this->cache->path);

        self::assertSame($first, $second);
        self::assertFileExists($second . self::MARKER, 'reused, not unpacked again');
    }

    #[Test]
    public function nothingButTheUnpackedCopyIsLeftInTheCache(): void
    {
        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame(['.', '..', basename($directory)], \Safe\scandir(\dirname($directory)), 'no staging directory left behind');
    }

    /** One directory per PHAR content: named by the first 16 hex digits of the PHAR's SHA-256. */
    #[Test]
    public function theCopyIsNamedAfterTheStartOfThePharsHash(): void
    {
        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame($this->expectedTarget(), $directory);
    }

    /**
     * A copy without its autoloader (a partial rm, a hand edit, a copied cache) is not reused,
     * and must not block the rename either: every later run would fail until the cache is deleted.
     */
    #[Test]
    public function anIncompleteCopyIsReplacedByAFreshOne(): void
    {
        $target = $this->expectedTarget();
        \Safe\mkdir($target . '/vendor', 0o755, true);
        \Safe\file_put_contents($target . '/partial', 'left by an interrupted copy');

        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame($target, $directory);
        self::assertFileExists($directory . '/' . DetectorUnpacker::AUTOLOAD);
        self::assertFileExists($directory . '/' . DetectorUnpacker::RULES_NEON);
        self::assertFileDoesNotExist($directory . '/partial', 'the incomplete copy is replaced, not merged into');
        self::assertSame(['.', '..', basename($directory)], \Safe\scandir(\dirname($directory)), 'nothing of the incomplete copy left beside it');
    }

    /** A file where the copy belongs is replaced the same way. */
    #[Test]
    public function aFileWhereTheCopyBelongsIsReplaced(): void
    {
        $target = $this->expectedTarget();
        \Safe\mkdir(\dirname($target), 0o755, true);
        \Safe\file_put_contents($target, 'not a directory');

        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertFileExists($directory . '/' . DetectorUnpacker::AUTOLOAD);
    }

    /** Each new detector PHAR is a new directory; the copies of earlier PHARs are removed. */
    #[Test]
    public function copiesOfOtherPharsAreRemovedWhenUnpacking(): void
    {
        $dir = $this->cache->path . '/' . DetectorUnpacker::DIR;
        \Safe\mkdir($dir . '/0123456789abcdef/vendor', 0o755, true);
        \Safe\file_put_contents($dir . '/0123456789abcdef/vendor/autoload.php', '<?php');
        \Safe\mkdir($dir . '/.unpackAbC123/vendor', 0o755, true);
        \Safe\file_put_contents($dir . '/stray', 'x');

        $directory = $this->unpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame(['.', '..', basename($directory)], \Safe\scandir($dir));
    }

    /** A cache that already holds the current copy is pruned too, so stale copies do not wait for the next PHAR. */
    #[Test]
    public function copiesOfOtherPharsAreRemovedWhenTheCurrentCopyIsReused(): void
    {
        $unpacker  = $this->unpacker();
        $directory = $unpacker->unpack(self::PHAR, $this->cache->path);
        \Safe\file_put_contents($directory . self::MARKER, 'kept');
        \Safe\mkdir(\dirname($directory) . '/fedcba9876543210/vendor', 0o755, true);

        self::assertSame($directory, $unpacker->unpack(self::PHAR, $this->cache->path));
        self::assertFileExists($directory . self::MARKER, 'the current copy is reused');
        self::assertSame(['.', '..', basename($directory)], \Safe\scandir(\dirname($directory)));
    }

    #[Test]
    public function aPharThatCannotBeUnpackedLeavesNoStagingDirectoryBehind(): void
    {
        $notAPhar = $this->cache->path . '/broken.phar';
        \Safe\file_put_contents($notAPhar, 'not a phar');

        $thrown = null;

        try {
            $this->unpacker()->unpack($notAPhar, $this->cache->path);
        } catch (UnexpectedValueException $unexpectedValueException) {
            $thrown = $unexpectedValueException;
        }

        self::assertInstanceOf(UnexpectedValueException::class, $thrown);
        self::assertSame(['.', '..'], \Safe\scandir($this->cache->path . '/' . DetectorUnpacker::DIR));
    }

    /**
     * Pruning is housekeeping and never decides the lane's verdict. A stale entry it cannot
     * remove (a copy a root container run left in a user's cache) is named in a warning and the
     * run goes on with the current copy; failing would fail every run until someone removed it.
     */
    #[Test]
    public function aStaleEntryThatCannotBeRemovedIsNamedAndTheRunGoesOn(): void
    {
        $dir     = $this->cache->path . '/' . DetectorUnpacker::DIR;
        $stuck   = $this->plantUnremovable($dir . '/0123456789abcdef');
        \Safe\mkdir($dir . '/fedcba9876543210/vendor', 0o755, true);
        $output   = new BufferedOutput();
        $unpacker = new DetectorUnpacker($output);

        $first  = $unpacker->unpack(self::PHAR, $this->cache->path);
        $second = $unpacker->unpack(self::PHAR, $this->cache->path);

        self::assertSame($this->expectedTarget(), $first);
        self::assertSame($first, $second, 'every run goes on, not only the first');
        self::assertFileExists($first . '/' . DetectorUnpacker::AUTOLOAD);
        self::assertEqualsCanonicalizing(['.', '..', basename($stuck), basename($first)], \Safe\scandir($dir), 'the entries after the stuck one are still pruned');
        $printed = $output->fetch();
        self::assertSame(2, substr_count($printed, self::COULD_NOT_REMOVE . $stuck . ','), 'named once per run: ' . $printed);
    }

    /**
     * An incomplete copy is moved aside before the fresh one is renamed into place. Removing the
     * moved copy is housekeeping too: one holding something the run cannot remove is named in a
     * warning, and the run goes on with the fresh copy that is already in place.
     */
    #[Test]
    public function anIncompleteCopyThatCannotBeRemovedIsNamedAndTheRunGoesOn(): void
    {
        $target = $this->expectedTarget();
        UnremovableTree::plant($target . self::STUCK);
        $output   = new BufferedOutput();
        $unpacker = new DetectorUnpacker($output);

        try {
            $first  = $unpacker->unpack(self::PHAR, $this->cache->path);
            $second = $unpacker->unpack(self::PHAR, $this->cache->path);
        } finally {
            $this->trackMovedUnremovable($target);
        }

        self::assertSame($target, $first);
        self::assertSame($first, $second, 'every run goes on, not only the first');
        self::assertFileExists($first . '/' . DetectorUnpacker::AUTOLOAD);
        self::assertDirectoryDoesNotExist($first . self::STUCK, 'the incomplete copy is replaced, not merged into');
        self::assertIsString($this->unremovable);
        self::assertStringContainsString('/.discard', $this->unremovable, 'the incomplete copy was moved aside');
        $discard = \dirname($this->unremovable, 2);
        $printed = $output->fetch();
        self::assertSame(2, substr_count($printed, self::COULD_NOT_REMOVE . $discard), 'named once per run: ' . $printed);
    }

    /**
     * When the fresh copy cannot be renamed into place, that failure is what the run reports.
     * An incomplete copy already moved aside that cannot be removed is still named, but the
     * warning does not say the lane goes on, as it does not.
     *
     * The cache's detector directory is reached through a link into the incomplete copy and
     * back out ('<copy>/..'), so moving the copy aside breaks every path in that directory, and
     * the rename of the fresh copy into place fails. The warning that rename raises is the
     * moment it has failed: the link is then pointed straight at the real directory, so what
     * the run does after the failure (removing its staging and the moved-aside copy) reaches
     * the paths it names, as it would in a cache whose rename failed for any other reason.
     */
    #[Test]
    public function whenTheFreshCopyCannotBeRenamedIntoPlaceTheWarningDoesNotSayTheLaneGoesOn(): void
    {
        $real = $this->cache->path . '/real';
        $link = $this->cache->path . '/' . DetectorUnpacker::DIR;
        $hash = basename($this->expectedTarget());
        UnremovableTree::plant($real . '/' . $hash . self::STUCK);
        \Safe\symlink($real . '/' . $hash . '/..', $link);
        $output   = new BufferedOutput();
        $thrown   = null;
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings, $link, $real): bool {
            $warnings[] = $message;
            if (1 === \count($warnings)) {
                \Safe\unlink($link);
                \Safe\symlink($real, $link);
            }

            return true;
        });

        try {
            new DetectorUnpacker($output)->unpack(self::PHAR, $this->cache->path);
        } catch (FilesystemException $filesystemException) {
            $thrown = $filesystemException;
        } finally {
            restore_error_handler();
            $this->trackMovedUnremovable($real . '/' . $hash);
        }

        self::assertInstanceOf(FilesystemException::class, $thrown, 'the failed rename fails the run');
        self::assertCount(1, $warnings, 'only the rename of the fresh copy into place fails: ' . implode("\n", $warnings));
        self::assertStringStartsWith('rename(' . $link . '/.unpack', $warnings[0]);
        self::assertIsString($this->unremovable);
        self::assertStringContainsString('/.discard', $this->unremovable, 'the incomplete copy was moved aside');
        $printed = $output->fetch();
        self::assertStringStartsWith(self::COULD_NOT_REMOVE, $printed);
        $named = strstr(substr($printed, \strlen(self::COULD_NOT_REMOVE)), ', ', true);
        self::assertIsString($named, 'the moved-aside copy is named: ' . $printed);
        self::assertSame(\Safe\realpath(\dirname($this->unremovable, 2)), \Safe\realpath($named), 'the path named is the moved-aside copy left behind');
        self::assertStringNotContainsString('goes on', $printed);
    }

    private function unpacker(): DetectorUnpacker
    {
        return new DetectorUnpacker(new NullOutput());
    }

    private function plantUnremovable(string $path): string
    {
        UnremovableTree::plant($path);
        $this->unremovable = $path;

        return $path;
    }

    /** Finds where the tree planted under $target was moved to, wherever the run left it, so tearDown() can take it down. */
    private function trackMovedUnremovable(string $target): void
    {
        $this->unremovable = $target . self::STUCK;
        foreach (\Safe\glob(\dirname($target) . '/.discard*/incomplete' . self::STUCK) as $moved) {
            if (\is_string($moved)) {
                $this->unremovable = $moved;
            }
        }
    }

    private function expectedTarget(): string
    {
        return $this->cache->path . '/' . DetectorUnpacker::DIR . '/' . substr(\Safe\hash_file('sha256', self::PHAR), 0, 16);
    }
}
