<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(TurboManifest::class)]
#[UsesClass(TurboPlatform::class)]
#[Small]
final class TurboManifestTest extends TestCase
{
    private const string VERSION = '2.3.1';

    private const string BINARY = 'linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string CORE = 'linux-gnu-x86_64/phpstan_turbo_core.so';

    private const string DIGEST = '62c7222f6c5458568d67443a7e14fac3c30619887736cc5473ccefd8098ca9bb';

    #[Test]
    public function itRoundTripsThroughJson(): void
    {
        $manifest = new TurboManifest(self::VERSION, [self::BINARY => self::DIGEST]);

        $read = TurboManifest::fromJson($manifest->toJson());

        self::assertSame(self::VERSION, $read->phpstanVersion);
        self::assertSame(self::DIGEST, $read->digestFor(self::BINARY));
        self::assertNull($read->digestFor('linux-gnu-arm64/phpstan_turbo-8.5.so'));
    }

    #[Test]
    public function theJsonIsSortedSoARegenerationWithNoChangeIsByteIdentical(): void
    {
        $one = new TurboManifest(self::VERSION, [self::CORE => self::DIGEST, self::BINARY => self::DIGEST]);
        $two = new TurboManifest(self::VERSION, [self::BINARY => self::DIGEST, self::CORE => self::DIGEST]);

        self::assertSame($one->toJson(), $two->toJson());
        self::assertStringEndsWith("\n", $one->toJson());
    }

    /** The phar loads a Turbo binary only with its core beside it, and both live in phpstan/phpstan's turbo-ext/. */
    #[Test]
    public function itListsTheUnixBinariesAndCoresOfARepositoryTree(): void
    {
        $tree = $this->treeOf(
            'turbo-ext/.version',
            'turbo-ext/' . self::BINARY,
            'turbo-ext/' . self::CORE,
            'turbo-ext/macos-arm64/phpstan_turbo-8.5-zts.so',
            'turbo-ext/windows-x86_64/phpstan_turbo-8.5.dll',
            'turbo-ext/windows-x86_64/phpstan_turbo_core.dll',
            'src/Turbo/TurboExtensionSelector.php',
        );

        self::assertSame(
            [self::BINARY, self::CORE, 'macos-arm64/phpstan_turbo-8.5-zts.so'],
            TurboManifest::pathsInRepositoryTree($tree),
            'the version file, directories, Windows builds and everything outside turbo-ext/ are left out',
        );
    }

    /** A binary without its core is a file the phar refuses to load, so a half-pinned host has no build. */
    #[Test]
    public function aBuildIsPinnedOnlyWhenBothTheBinaryAndTheCoreAre(): void
    {
        $linux = new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false);

        self::assertTrue(new TurboManifest(self::VERSION, [self::BINARY => self::DIGEST, self::CORE => self::DIGEST])->pinsBuildFor($linux));
        self::assertFalse(new TurboManifest(self::VERSION, [self::BINARY => self::DIGEST])->pinsBuildFor($linux));
        self::assertFalse(new TurboManifest(self::VERSION, [self::CORE => self::DIGEST])->pinsBuildFor($linux));
        self::assertFalse(new TurboManifest(self::VERSION, [self::BINARY => self::DIGEST, self::CORE => self::DIGEST])->pinsBuildFor(new TurboPlatform('Windows', 'AMD64', '', '8.5', false)));
    }

    #[Test]
    public function aTruncatedRepositoryTreeIsRejectedForItWouldPinHalfTheFiles(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::pathsInRepositoryTree(\Safe\json_encode(['truncated' => true, 'tree' => []]));
    }

    #[Test]
    public function aRepositoryTreeWithNoTurboFilesIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::pathsInRepositoryTree($this->treeOf('README.md'));
    }

    #[Test]
    public function aDigestThatIsNotSha256IsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::fromJson(\Safe\json_encode(['phpstan' => self::VERSION, 'files' => [self::BINARY => 'not-a-digest']]));
    }

    #[Test]
    public function aManifestWithNoVersionIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::fromJson(\Safe\json_encode(['files' => []]));
    }

    /** GitHub's git-tree response: a directory entry, then one blob per path. */
    private function treeOf(string ...$blobPaths): string
    {
        $entries = [['path' => 'turbo-ext/linux-gnu-x86_64', 'type' => 'tree']];
        foreach ($blobPaths as $path) {
            $entries[] = ['path' => $path, 'type' => 'blob'];
        }

        return \Safe\json_encode(['truncated' => false, 'tree' => $entries]);
    }
}
