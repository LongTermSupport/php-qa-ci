<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Turbo\TurboFreshness;
use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The installed Turbo binary and core against the digests the committed manifest pins, over a
 * throwaway vendor-phar directory.
 *
 * @internal
 */
#[CoversClass(TurboFreshness::class)]
#[UsesClass(TurboManifest::class)]
#[UsesClass(TurboPlatform::class)]
#[Small]
final class TurboFreshnessTest extends TestCase
{
    private const string BINARY_PATH = 'linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string CORE_PATH = 'linux-gnu-x86_64/phpstan_turbo_core.so';

    private const string BINARY_BYTES = 'the pinned binary';

    private const string CORE_BYTES = 'the pinned core';

    private const string MISSING = ' is missing';

    private const string TAIL = ' PHPStan and Rector run without Turbo, and slower. Refresh it with: php bin/turbo-install';

    private const string STALE = ' is stale, its SHA-256 differs from the one vendor-phar/turbo-ext.json pins.';

    private const string PREFIX = 'PHPStan Turbo: vendor-phar/turbo-ext/';

    private TempDir $pharDir;

    protected function setUp(): void
    {
        $this->pharDir = TempDir::create('turbo-freshness');
    }

    protected function tearDown(): void
    {
        $this->pharDir->remove();
    }

    #[Test]
    public function aBinaryAndCoreThatMatchTheManifestAreFresh(): void
    {
        $this->pin();
        $this->install(self::BINARY_BYTES, self::CORE_BYTES);

        self::assertNull($this->freshness()->problem($this->pharDir->path));
    }

    #[Test]
    public function aBinaryWhoseDigestDiffersFromTheManifestIsStaleAndTheMessageGivesTheRefreshCommand(): void
    {
        $this->pin();
        $this->install('a binary from an older PHPStan', self::CORE_BYTES);

        self::assertSame(
            self::PREFIX . self::BINARY_PATH . self::STALE . self::TAIL,
            $this->freshness()->problem($this->pharDir->path),
        );
    }

    #[Test]
    public function aMissingBinaryIsReportedAsMissingWithTheRefreshCommand(): void
    {
        $this->pin();
        $this->pharDir->write('turbo-ext/' . self::CORE_PATH, self::CORE_BYTES);

        self::assertSame(
            self::PREFIX . self::BINARY_PATH . self::MISSING . '.' . self::TAIL,
            $this->freshness()->problem($this->pharDir->path),
        );
    }

    #[Test]
    public function aCheckoutWithNoTurboDirectoryAtAllIsMissing(): void
    {
        $this->pin();

        self::assertStringContainsString(self::BINARY_PATH . self::MISSING, (string)$this->freshness()->problem($this->pharDir->path));
    }

    #[Test]
    public function aCoreWhoseDigestDiffersFromTheManifestIsStale(): void
    {
        $this->pin();
        $this->install(self::BINARY_BYTES, 'a core from an older PHPStan');

        self::assertSame(
            self::PREFIX . self::CORE_PATH . self::STALE . self::TAIL,
            $this->freshness()->problem($this->pharDir->path),
        );
    }

    #[Test]
    public function aMissingCoreIsReportedAsMissing(): void
    {
        $this->pin();
        $this->pharDir->write('turbo-ext/' . self::BINARY_PATH, self::BINARY_BYTES);

        self::assertSame(
            self::PREFIX . self::CORE_PATH . self::MISSING . '.' . self::TAIL,
            $this->freshness()->problem($this->pharDir->path),
        );
    }

    #[Test]
    public function aHostTheManifestDoesNotCoverIsSilent(): void
    {
        $this->pin();

        self::assertNull(new TurboFreshness(new TurboPlatform('Windows', 'AMD64', '', '8.5', false))->problem($this->pharDir->path));
    }

    #[Test]
    public function aManifestThatPinsNoBuildForTheHostIsSilent(): void
    {
        $this->pharDir->write('turbo-ext.json', new TurboManifest('2.3.2', [])->toJson());

        self::assertNull($this->freshness()->problem($this->pharDir->path));
    }

    #[Test]
    public function aManifestThatPinsOnlyTheCoreIsSilent(): void
    {
        $this->pharDir->write('turbo-ext.json', new TurboManifest('2.3.2', [self::CORE_PATH => hash('sha256', self::CORE_BYTES)])->toJson());

        self::assertNull($this->freshness()->problem($this->pharDir->path));
    }

    #[Test]
    public function aDirectoryWithNoManifestIsSilent(): void
    {
        self::assertNull($this->freshness()->problem($this->pharDir->path));
    }

    #[Test]
    public function withoutAPlatformItJudgesTheRunningHost(): void
    {
        $platform = TurboPlatform::fromRuntime();
        $binary   = $platform->binaryPath();
        $core     = $platform->corePath();
        if (null === $binary || null === $core) {
            self::assertNull(new TurboFreshness()->problem($this->pharDir->path), 'a host with no build is silent');

            return;
        }

        $this->pharDir->write('turbo-ext.json', new TurboManifest('2.3.2', [$binary => hash('sha256', 'b'), $core => hash('sha256', 'c')])->toJson());

        self::assertStringContainsString($binary . self::MISSING, (string)new TurboFreshness()->problem($this->pharDir->path));
    }

    private function freshness(): TurboFreshness
    {
        return new TurboFreshness(new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false));
    }

    private function pin(): void
    {
        $this->pharDir->write('turbo-ext.json', new TurboManifest('2.3.2', [
            self::BINARY_PATH => hash('sha256', self::BINARY_BYTES),
            self::CORE_PATH   => hash('sha256', self::CORE_BYTES),
        ])->toJson());
    }

    private function install(string $binary, string $core): void
    {
        $this->pharDir->write('turbo-ext/' . self::BINARY_PATH, $binary);
        $this->pharDir->write('turbo-ext/' . self::CORE_PATH, $core);
    }
}
