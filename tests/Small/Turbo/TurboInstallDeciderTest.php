<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\Dto\TurboDecisionDto;
use LTS\PHPQA\Turbo\TurboActionEnum;
use LTS\PHPQA\Turbo\TurboInstallDecider;
use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TurboInstallDecider::class)]
#[UsesClass(TurboDecisionDto::class)]
#[UsesClass(TurboManifest::class)]
#[UsesClass(TurboPlatform::class)]
#[Small]
final class TurboInstallDeciderTest extends TestCase
{
    private const string PHP_MINOR = '8.5';

    private const string VERSION = '2.3.0';

    private const string ASSET = 'php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip';

    private const string DIGEST = '62c7222f6c5458568d67443a7e14fac3c30619887736cc5473ccefd8098ca9bb';

    private TurboInstallDecider $decider;

    private TurboPlatform $linux;

    protected function setUp(): void
    {
        $this->decider = new TurboInstallDecider();
        $this->linux   = new TurboPlatform('Linux', 'x86_64', 'gnu', self::PHP_MINOR, false);
    }

    #[Test]
    public function aHostWithNoBinaryYetFetchesTheManifestsAsset(): void
    {
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $this->linux, null);

        self::assertSame(TurboActionEnum::Fetch, $decision->action);
        self::assertSame(self::ASSET, $decision->asset);
        self::assertSame(self::DIGEST, $decision->digest);
    }

    #[Test]
    public function aBinaryInstalledFromTheSameAssetAndDigestIsReady(): void
    {
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $this->linux, TurboInstallDecider::stamp(self::ASSET, self::DIGEST));

        self::assertSame(TurboActionEnum::Ready, $decision->action);
    }

    /** The stamp names the asset and digest it came from, so a phar update replaces the binary. */
    #[Test]
    public function aBinaryFromAnotherReleaseIsFetchedAgain(): void
    {
        $older    = TurboInstallDecider::stamp('php_phpstan_turbo-2.2.16_php8.5-x86_64-linux-glibc.zip', self::DIGEST);
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $this->linux, $older);

        self::assertSame(TurboActionEnum::Fetch, $decision->action);
    }

    #[Test]
    public function aMissingManifestIsBroken(): void
    {
        $decision = $this->decider->decide(null, self::VERSION, $this->linux, null);

        self::assertSame(TurboActionEnum::Broken, $decision->action);
        self::assertStringContainsString('turbo-ext.json', $decision->message);
    }

    #[Test]
    public function anUnknownPharVersionIsBroken(): void
    {
        $decision = $this->decider->decide($this->manifest(), null, $this->linux, null);

        self::assertSame(TurboActionEnum::Broken, $decision->action);
        self::assertStringContainsString('phive.xml', $decision->message);
    }

    /**
     * A binary from another release does not load, and PHPStan then runs without Turbo saying
     * nothing, so a manifest for another phar version is a broken checkout, not something to fetch.
     */
    #[Test]
    public function aManifestForAnotherPharVersionIsBroken(): void
    {
        $decision = $this->decider->decide($this->manifest(), '2.3.1', $this->linux, null);

        self::assertSame(TurboActionEnum::Broken, $decision->action);
        self::assertStringContainsString('2.3.0', $decision->message);
        self::assertStringContainsString('2.3.1', $decision->message);
    }

    #[Test]
    public function aHostUpstreamBuildsNothingForIsUnsupported(): void
    {
        $windows  = new TurboPlatform('Windows', 'AMD64', '', self::PHP_MINOR, false);
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $windows, null);

        self::assertSame(TurboActionEnum::Unsupported, $decision->action);
        self::assertStringContainsString('Windows', $decision->message);
    }

    #[Test]
    public function aHostWhoseAssetTheReleaseLacksIsUnsupported(): void
    {
        $arm      = new TurboPlatform('Linux', 'aarch64', 'gnu', self::PHP_MINOR, false);
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $arm, null);

        self::assertSame(TurboActionEnum::Unsupported, $decision->action);
        self::assertStringContainsString('php_phpstan_turbo-2.3.0_php8.5-arm64-linux-glibc.zip', $decision->message);
    }

    private function manifest(): TurboManifest
    {
        return new TurboManifest(self::VERSION, [self::ASSET => self::DIGEST]);
    }
}
