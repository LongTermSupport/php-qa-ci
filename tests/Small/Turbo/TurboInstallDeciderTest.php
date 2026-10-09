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

    private const string VERSION = '2.3.1';

    private const string BINARY = 'linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string CORE = 'linux-gnu-x86_64/phpstan_turbo_core.so';

    private const string BINARY_DIGEST = '62c7222f6c5458568d67443a7e14fac3c30619887736cc5473ccefd8098ca9bb';

    private const string CORE_DIGEST = '9e99c055b3da7559b4ec0a486391020184d6a1296dc45ec019da67a59f64c2ba';

    private TurboInstallDecider $decider;

    private TurboPlatform $linux;

    protected function setUp(): void
    {
        $this->decider = new TurboInstallDecider();
        $this->linux   = new TurboPlatform('Linux', 'x86_64', 'gnu', self::PHP_MINOR, false);
    }

    /** The phar loads the binary only with its core beside it, so a host needs both files. */
    #[Test]
    public function aHostWithNoBinaryYetFetchesTheCoreAndTheBinary(): void
    {
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $this->linux, null);

        self::assertSame(TurboActionEnum::Fetch, $decision->action);
        self::assertSame([self::CORE => self::CORE_DIGEST, self::BINARY => self::BINARY_DIGEST], $decision->files);
    }

    #[Test]
    public function filesInstalledFromTheSameDigestsAreReady(): void
    {
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $this->linux, TurboInstallDecider::stamp($this->files()));

        self::assertSame(TurboActionEnum::Ready, $decision->action);
    }

    /** The stamp names every file and digest it came from, so a phar update replaces both. */
    #[Test]
    public function filesFromAnotherReleaseAreFetchedAgain(): void
    {
        $older    = TurboInstallDecider::stamp([self::CORE => self::CORE_DIGEST, self::BINARY => str_repeat('1', 64)]);
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
        $decision = $this->decider->decide($this->manifest(), '2.3.2', $this->linux, null);

        self::assertSame(TurboActionEnum::Broken, $decision->action);
        self::assertStringContainsString('2.3.1', $decision->message);
        self::assertStringContainsString('2.3.2', $decision->message);
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
    public function aHostWhoseFilesTheReleaseLacksIsUnsupported(): void
    {
        $arm      = new TurboPlatform('Linux', 'aarch64', 'gnu', self::PHP_MINOR, false);
        $decision = $this->decider->decide($this->manifest(), self::VERSION, $arm, null);

        self::assertSame(TurboActionEnum::Unsupported, $decision->action);
        self::assertStringContainsString('turbo-ext/linux-gnu-arm64/', $decision->message);
    }

    /** A binary without its core is a file PHPStan refuses to load, which is worse than none. */
    #[Test]
    public function aHostWhoseCoreTheReleaseLacksIsUnsupported(): void
    {
        $manifest = new TurboManifest(self::VERSION, [self::BINARY => self::BINARY_DIGEST]);
        $decision = $this->decider->decide($manifest, self::VERSION, $this->linux, null);

        self::assertSame(TurboActionEnum::Unsupported, $decision->action);
        self::assertStringContainsString(self::CORE, $decision->message);
    }

    private function manifest(): TurboManifest
    {
        return new TurboManifest(self::VERSION, $this->files());
    }

    /** @return array<string, string> */
    private function files(): array
    {
        return [self::CORE => self::CORE_DIGEST, self::BINARY => self::BINARY_DIGEST];
    }
}
