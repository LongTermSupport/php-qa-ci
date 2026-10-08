<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\TurboManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(TurboManifest::class)]
#[Small]
final class TurboManifestTest extends TestCase
{
    private const string VERSION = '2.3.0';

    private const string ASSET = 'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip';

    private const string DIGEST = '62c7222f6c5458568d67443a7e14fac3c30619887736cc5473ccefd8098ca9bb';

    #[Test]
    public function itRoundTripsThroughJson(): void
    {
        $manifest = new TurboManifest(self::VERSION, [self::ASSET => self::DIGEST]);

        $read = TurboManifest::fromJson($manifest->toJson());

        self::assertSame(self::VERSION, $read->phpstanVersion);
        self::assertSame(self::DIGEST, $read->digestFor(self::ASSET));
        self::assertNull($read->digestFor('php_phpstan_turbo-2.3.0_php8.5-arm64-linux-glibc.zip'));
    }

    #[Test]
    public function theJsonIsSortedSoARegenerationWithNoChangeIsByteIdentical(): void
    {
        $one = new TurboManifest(self::VERSION, ['b.zip' => self::DIGEST, 'a.zip' => self::DIGEST]);
        $two = new TurboManifest(self::VERSION, ['a.zip' => self::DIGEST, 'b.zip' => self::DIGEST]);

        self::assertSame($one->toJson(), $two->toJson());
        self::assertStringEndsWith("\n", $one->toJson());
    }

    #[Test]
    public function itReadsTheReleaseApiDigestsKeepingOnlyTheUnixAssets(): void
    {
        $release = \Safe\json_encode([
            'tag_name' => self::VERSION,
            'assets'   => [
                ['name' => self::ASSET, 'digest' => 'sha256:' . self::DIGEST],
                ['name' => 'php_phpstan_turbo-2.3.0-8.5-nts-vs17-x86_64.zip', 'digest' => 'sha256:' . self::DIGEST],
                ['name' => 'php_phpstan_turbo-2.3.0_php8.5-arm64-darwin-bsdlibc.zip', 'digest' => null],
            ],
        ]);

        $manifest = TurboManifest::fromReleaseApi($release);

        self::assertSame(self::VERSION, $manifest->phpstanVersion);
        self::assertSame([self::ASSET => self::DIGEST], $manifest->assets, 'Windows builds and assets with no published digest are left out');
    }

    #[Test]
    public function aDigestThatIsNotSha256IsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::fromJson(\Safe\json_encode(['phpstan' => self::VERSION, 'assets' => [self::ASSET => 'not-a-digest']]));
    }

    #[Test]
    public function aManifestWithNoVersionIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TurboManifest::fromJson(\Safe\json_encode(['assets' => []]));
    }
}
