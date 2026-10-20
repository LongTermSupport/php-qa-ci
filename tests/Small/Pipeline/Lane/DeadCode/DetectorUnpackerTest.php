<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
    private const string PHAR = __DIR__ . '/../../../../../vendor-phar/dead-code-detector.phar';

    private TempDir $cache;

    protected function setUp(): void
    {
        $this->cache = TempDir::create('phpqa-detector-unpacker-test');
    }

    protected function tearDown(): void
    {
        $this->cache->remove();
    }

    #[Test]
    public function theDetectorIsUnpackedAsPlainFilesIntoTheCache(): void
    {
        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

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
        $unpacker = new DetectorUnpacker();
        $first    = $unpacker->unpack(self::PHAR, $this->cache->path);
        \Safe\file_put_contents($first . '/marker', 'kept');

        $second = $unpacker->unpack(self::PHAR, $this->cache->path);

        self::assertSame($first, $second);
        self::assertFileExists($second . '/marker', 'reused, not unpacked again');
    }

    #[Test]
    public function nothingButTheUnpackedCopyIsLeftInTheCache(): void
    {
        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame(['.', '..', basename($directory)], \Safe\scandir(\dirname($directory)), 'no staging directory left behind');
    }

    /** One directory per PHAR content: named by the first 16 hex digits of the PHAR's SHA-256. */
    #[Test]
    public function theCopyIsNamedAfterTheStartOfThePharsHash(): void
    {
        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame(
            $this->cache->path . '/' . DetectorUnpacker::DIR . '/' . substr(\Safe\hash_file('sha256', self::PHAR), 0, 16),
            $directory,
        );
    }

    /**
     * A copy without its autoloader (a partial rm, a hand edit, a copied cache) is not reused,
     * and must not block the rename either: every later run would fail until the cache is deleted.
     */
    #[Test]
    public function anIncompleteCopyIsReplacedByAFreshOne(): void
    {
        $target = $this->cache->path . '/' . DetectorUnpacker::DIR . '/' . substr(\Safe\hash_file('sha256', self::PHAR), 0, 16);
        \Safe\mkdir($target . '/vendor', 0o755, true);
        \Safe\file_put_contents($target . '/partial', 'left by an interrupted copy');

        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

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
        $target = $this->cache->path . '/' . DetectorUnpacker::DIR . '/' . substr(\Safe\hash_file('sha256', self::PHAR), 0, 16);
        \Safe\mkdir(\dirname($target), 0o755, true);
        \Safe\file_put_contents($target, 'not a directory');

        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

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

        $directory = new DetectorUnpacker()->unpack(self::PHAR, $this->cache->path);

        self::assertSame(['.', '..', basename($directory)], \Safe\scandir($dir));
    }

    /** A cache that already holds the current copy is pruned too, so stale copies do not wait for the next PHAR. */
    #[Test]
    public function copiesOfOtherPharsAreRemovedWhenTheCurrentCopyIsReused(): void
    {
        $unpacker  = new DetectorUnpacker();
        $directory = $unpacker->unpack(self::PHAR, $this->cache->path);
        \Safe\file_put_contents($directory . '/marker', 'kept');
        \Safe\mkdir(\dirname($directory) . '/fedcba9876543210/vendor', 0o755, true);

        self::assertSame($directory, $unpacker->unpack(self::PHAR, $this->cache->path));
        self::assertFileExists($directory . '/marker', 'the current copy is reused');
        self::assertSame(['.', '..', basename($directory)], \Safe\scandir(\dirname($directory)));
    }

    #[Test]
    public function aPharThatCannotBeUnpackedLeavesNoStagingDirectoryBehind(): void
    {
        $notAPhar = $this->cache->path . '/broken.phar';
        \Safe\file_put_contents($notAPhar, 'not a phar');

        $thrown = null;

        try {
            new DetectorUnpacker()->unpack($notAPhar, $this->cache->path);
        } catch (UnexpectedValueException $unexpectedValueException) {
            $thrown = $unexpectedValueException;
        }

        self::assertInstanceOf(UnexpectedValueException::class, $thrown);
        self::assertSame(['.', '..'], \Safe\scandir($this->cache->path . '/' . DetectorUnpacker::DIR));
    }
}
