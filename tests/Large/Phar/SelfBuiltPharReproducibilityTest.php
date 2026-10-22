<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Phar;

use FilesystemIterator;
use Phar;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Each committed self-built PHAR carries nothing that varies from one build to
 * the next. A rebuild with no dependency change then reproduces it byte for
 * byte, so the dependency update's diff names only the tools that really
 * changed, and the changelog it writes is complete.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class SelfBuiltPharReproducibilityTest extends TestCase
{
    /** Box names the PHAR this way when its config sets no alias: random per build. */
    private const string GENERATED_ALIAS = 'box-auto-generated-alias-';

    /** The end of every PHAR stub; the binary manifest follows it. */
    private const string HALT_COMPILER = '__HALT_COMPILER(); ?>';

    /** Bytes of an entry record between its name and its metadata length: sizes, timestamp, CRC32 and flags. */
    private const int ENTRY_FIXED_FIELDS_LENGTH = 20;

    /**
     * Box writes the files it compiles to a temporary directory and, unless told to sort them, adds
     * them in the order that directory lists them. That order belongs to the build host's
     * filesystem (hash order on ext4, creation order on btrfs), so the same inputs built on another
     * host give the same entries in another order, and different bytes.
     */
    #[Test]
    #[DataProvider('selfBuiltTools')]
    public function itsEntriesAreInByteOrder(string $tool): void
    {
        $previous   = null;
        $outOfOrder = null;
        foreach ($this->manifestEntryNames($tool) as $name) {
            if (null !== $previous && strcmp($previous, $name) > 0) {
                $outOfOrder = \sprintf('"%s" before "%s"', $previous, $name);

                break;
            }

            $previous = $name;
        }

        self::assertNull($outOfOrder, \sprintf("vendor-phar/%s.phar lists its entries in the order the build host's filesystem returned them, so a rebuild on another host reorders them: scripts/build-phar.bash must compile with Box's --sort-compiled-files", $tool));
    }

    #[Test]
    #[DataProvider('selfBuiltTools')]
    public function itsStubNamesAFixedAlias(string $tool): void
    {
        $stub = new Phar($this->pharPath($tool))->getStub();

        self::assertStringNotContainsString(self::GENERATED_ALIAS, $stub, \sprintf('build/%s/box.json.dist sets no "alias", so Box picks a random one per build', $tool));
    }

    #[Test]
    #[DataProvider('selfBuiltTools')]
    public function itsRootPackageRecordsNoCommitOfThisRepository(string $tool): void
    {
        $root = $this->rootPackage($tool);

        $version = $root['pretty_version'] ?? null;

        self::assertNull($root['reference'] ?? null, \sprintf('vendor-phar/%s.phar records the git commit it was built from as its root package reference: build it with COMPOSER_ROOT_VERSION set', $tool));
        self::assertIsString($version);
        self::assertStringStartsNotWith('dev-', $version, \sprintf('vendor-phar/%s.phar records the branch it was built on as its root package version', $tool));
    }

    /** @return iterable<string, array{string}> one case per build/<tool>/ manifest */
    public static function selfBuiltTools(): iterable
    {
        foreach (new FilesystemIterator(self::libraryRoot() . '/build') as $directory) {
            if (!$directory instanceof SplFileInfo || !is_file($directory->getPathname() . '/composer.json')) {
                continue;
            }

            $tool = $directory->getFilename();

            yield $tool => [$tool];
        }
    }

    private static function libraryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function pharPath(string $tool): string
    {
        return self::libraryRoot() . '/vendor-phar/' . $tool . '.phar';
    }

    /**
     * The entry names in the order the PHAR's binary manifest stores them, which is the order of
     * the file contents after it.
     *
     * @return list<string>
     */
    private function manifestEntryNames(string $tool): array
    {
        $bytes        = \Safe\file_get_contents($this->pharPath($tool));
        $haltPosition = strpos($bytes, self::HALT_COMPILER);
        self::assertIsInt($haltPosition, \sprintf('vendor-phar/%s.phar has no PHAR stub', $tool));

        $offset = $haltPosition + \strlen(self::HALT_COMPILER);
        $offset += "\r\n" === substr($bytes, $offset, 2) ? 2 : 1;
        // manifest length (4), then the entry count
        $offset += 4;
        $count = $this->uint32($bytes, $offset);
        // entry count (4), API version (2), global flags (4)
        $offset += 10;
        $aliasLength = $this->uint32($bytes, $offset);
        $offset += 4 + $aliasLength;
        $metadataLength = $this->uint32($bytes, $offset);
        $offset += 4 + $metadataLength;

        $names = [];
        for ($index = 0; $index < $count; ++$index) {
            $nameLength = $this->uint32($bytes, $offset);
            $names[]    = substr($bytes, $offset + 4, $nameLength);
            $offset += 4 + $nameLength + self::ENTRY_FIXED_FIELDS_LENGTH;
            $entryMetadataLength = $this->uint32($bytes, $offset);
            $offset += 4 + $entryMetadataLength;
        }

        return $names;
    }

    private function uint32(string $bytes, int $offset): int
    {
        $value = \Safe\unpack('V', $bytes, $offset);
        self::assertIsInt($value[1] ?? null);

        return $value[1];
    }

    /**
     * The root package record of Composer's generated `installed.php` inside the PHAR.
     *
     * @return array<array-key, mixed>
     */
    private function rootPackage(string $tool): array
    {
        $installed = require 'phar://' . $this->pharPath($tool) . '/vendor/composer/installed.php';
        self::assertIsArray($installed);
        self::assertIsArray($installed['root'] ?? null);

        return $installed['root'];
    }
}
