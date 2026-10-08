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
            \Safe\file_get_contents('phar://' . realpath(self::PHAR) . '/' . DetectorUnpacker::RULES_NEON),
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
}
