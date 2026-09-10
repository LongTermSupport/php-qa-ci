<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ShellCheck;

use LTS\PHPQA\ShellCheck\Dto\InstallDecisionDto;
use LTS\PHPQA\ShellCheck\InstallActionEnum;
use LTS\PHPQA\ShellCheck\InstallDecider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InstallDecider::class)]
#[UsesClass(InstallDecisionDto::class)]
#[Small]
final class InstallDeciderTest extends TestCase
{
    private const string PINNED = 'v0.11.0';

    private const string NEWER = 'v0.12.0';

    private InstallDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new InstallDecider();
    }

    #[Test]
    public function aCompleteCheckoutNeedsNothingDoingOnInstall(): void
    {
        $decision = $this->decider->forInstall(true, self::PINNED);

        self::assertSame(InstallActionEnum::Ready, $decision->action);
        self::assertNull($decision->version);
    }

    /**
     * The binary is committed, so its absence is a broken checkout rather than
     * something to fetch behind the user's back on a routine `composer install`.
     */
    #[Test]
    public function aMissingBinaryOnInstallIsBrokenRatherThanFetched(): void
    {
        $decision = $this->decider->forInstall(false, self::PINNED);

        self::assertSame(InstallActionEnum::Broken, $decision->action);
        self::assertStringContainsString('missing', $decision->message);
    }

    #[Test]
    public function aMissingPinOnInstallIsAlsoBroken(): void
    {
        $decision = $this->decider->forInstall(true, null);

        self::assertSame(InstallActionEnum::Broken, $decision->action);
    }

    #[Test]
    public function anUpdateToANewerReleaseFetchesThatRelease(): void
    {
        $decision = $this->decider->forUpdate(true, self::PINNED, self::NEWER);

        self::assertSame(InstallActionEnum::Fetch, $decision->action);
        self::assertSame(self::NEWER, $decision->version);
    }

    #[Test]
    public function anUpdateThatIsAlreadyCurrentDoesNothing(): void
    {
        $decision = $this->decider->forUpdate(true, self::PINNED, self::PINNED);

        self::assertSame(InstallActionEnum::Ready, $decision->action);
        self::assertStringContainsString(self::PINNED, $decision->message);
    }

    /**
     * The pin and the binary can disagree: a checkout whose binary went missing
     * still names a version, and that must be refetched rather than reported
     * up to date.
     */
    #[Test]
    public function aCurrentPinWithNoBinaryStillFetches(): void
    {
        $decision = $this->decider->forUpdate(false, self::PINNED, self::PINNED);

        self::assertSame(InstallActionEnum::Fetch, $decision->action);
        self::assertSame(self::PINNED, $decision->version);
    }

    /**
     * A rate limit or an outage must not fail the run or move the pin: the
     * committed binary is still the pinned one and still correct.
     */
    #[Test]
    public function anUnreachableReleaseApiLeavesThePinAlone(): void
    {
        $decision = $this->decider->forUpdate(true, self::PINNED, null);

        self::assertSame(InstallActionEnum::Ready, $decision->action);
        self::assertStringContainsString('could not', $decision->message);
        self::assertStringContainsString(self::PINNED, $decision->message);
    }

    #[Test]
    public function anUnpinnedCheckoutTakesWhateverTheApiReports(): void
    {
        $decision = $this->decider->forUpdate(false, null, self::NEWER);

        self::assertSame(InstallActionEnum::Fetch, $decision->action);
        self::assertSame(self::NEWER, $decision->version);
    }

    /**
     * With no pin and no answer from the API there is no version to name, and
     * inventing one would write a wrong pin.
     */
    #[Test]
    public function anUnpinnedCheckoutWithNoApiAnswerIsBroken(): void
    {
        $decision = $this->decider->forUpdate(false, null, null);

        self::assertSame(InstallActionEnum::Broken, $decision->action);
    }
}
