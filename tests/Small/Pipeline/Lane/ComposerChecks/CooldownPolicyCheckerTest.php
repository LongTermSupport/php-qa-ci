<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ComposerChecks;

use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownPolicyChecker;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CooldownPolicyChecker::class)]
#[UsesClass(CooldownReport::class)]
#[Small]
final class CooldownPolicyCheckerTest extends TestCase
{
    private const string MODERN = 'Composer version 2.11.1 2026-10-30 14:52:10';

    private const string STAR = '*';

    private const string OWN_VENDOR_PATTERN = 'acme/' . self::STAR;

    private const string REASON = 'our own packages are released by us, not by a stranger';

    private const string TODAY = '2026-11-02';

    private const string PERIOD = 'period';

    private const string SEVEN_DAYS = '7 days';

    private const string IGNORE = 'ignore';

    private const string NAME = 'name';

    private const string OWN_NAME = 'acme/app';

    private const string CONFIG = 'config';

    private const string POLICY = 'policy';

    private const string COOLDOWN = 'cooldown';

    private const string OVERRIDE_PACKAGE = 'thirdparty/lib';

    private const string OVERRIDE_REASON = 'SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/acme/app/pull/7; remove after 2026-11-20';

    #[Test]
    public function aCorrectCooldownProducesNoFindings(): void
    {
        $report = $this->inspect($this->compliant());

        self::assertSame([], $report->problems);
        self::assertSame([], $report->overrides);
    }

    #[Test]
    public function anOlderComposerIsReportedAsNotApplyingTheCooldown(): void
    {
        $report = $this->inspect($this->compliant(), 'Composer version 2.9.5 2026-01-01 00:00:00');

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('Composer 2.9.5 is older than 2.11', $report->problems[0]);
        self::assertStringContainsString('upgrade Composer to 2.11 or newer', $report->problems[0]);
    }

    #[Test]
    public function composerTwoElevenExactlyIsAccepted(): void
    {
        self::assertSame([], $this->inspect($this->compliant(), 'Composer version 2.11.0 2026-10-01 00:00:00')->problems);
    }

    #[Test]
    public function aVersionNobodyCanReadIsAFindingByName(): void
    {
        foreach ([$this->inspect($this->compliant(), 'something else entirely'), $this->inspect($this->compliant(), null)] as $report) {
            self::assertCount(1, $report->problems);
            self::assertStringContainsString('could not read the installed Composer version', $report->problems[0]);
        }
    }

    #[Test]
    public function noPolicyAtAllIsReportedAsNoCooldownConfigured(): void
    {
        $report = $this->inspect([self::NAME => self::OWN_NAME]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('no cooldown configured', $report->problems[0]);
        self::assertStringContainsString('"cooldown": {"period": "7 days"', $report->problems[0]);
    }

    /**
     * @param array<string, mixed> $json
     */
    #[Test]
    #[DataProvider('periodNotSet')]
    public function anUnsetOrZeroPeriodIsReported(array $json, string $expected): void
    {
        $joined = implode("\n", $this->inspect($json)->problems);

        self::assertStringContainsString($expected, $joined);
        self::assertStringContainsString('"period": "7 days"', $joined);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function periodNotSet(): iterable
    {
        $ignore = [self::OWN_VENDOR_PATTERN => self::REASON];

        yield 'period missing' => [self::withCooldown([self::IGNORE => $ignore]), 'period is not set'];

        yield 'period null' => [self::withCooldown([self::PERIOD => null, self::IGNORE => $ignore]), 'period is not set'];

        yield 'period empty string' => [self::withCooldown([self::PERIOD => '', self::IGNORE => $ignore]), 'period is not set'];

        yield 'period integer zero' => [self::withCooldown([self::PERIOD => 0, self::IGNORE => $ignore]), 'period is zero'];

        yield 'period zero days' => [self::withCooldown([self::PERIOD => '0 days', self::IGNORE => $ignore]), 'period is zero'];

        yield 'cooldown true without period' => [[self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => [self::COOLDOWN => true]]], 'period is not set'];
    }

    #[Test]
    public function aPositivePeriodInAnyAcceptedFormIsFine(): void
    {
        foreach (['7 days', '1 week', '24 hours', '3', 604800] as $period) {
            $report = $this->inspect(self::withCooldown([self::PERIOD => $period, self::IGNORE => [self::OWN_VENDOR_PATTERN => self::REASON]]));

            self::assertSame([], $report->problems, 'period ' . var_export($period, true));
        }
    }

    #[Test]
    #[DataProvider('malformedPeriods')]
    public function aPeriodComposerDoesNotAcceptIsAFindingByName(mixed $period): void
    {
        $report = $this->inspect(self::withCooldown([self::PERIOD => $period, self::IGNORE => [self::OWN_VENDOR_PATTERN => self::REASON]]));

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('period is not a form Composer accepts', $report->problems[0]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPeriods(): iterable
    {
        yield 'array' => [['7 days']];

        yield 'float' => [7.5];

        yield 'boolean' => [true];

        yield 'negative integer' => [-1];

        yield 'relative phrase' => ['tomorrow'];
    }

    /**
     * @param array<string, mixed> $json
     */
    #[Test]
    #[DataProvider('switchedOff')]
    public function aCooldownSwitchedOffInTheFileIsReported(array $json, string $expected): void
    {
        $report = $this->inspect($json);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString($expected, $report->problems[0]);
        self::assertStringContainsString('switches the cooldown off', $report->problems[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function switchedOff(): iterable
    {
        yield 'policy false' => [[self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => false]], 'config.policy is false'];

        yield 'cooldown false' => [[self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => [self::COOLDOWN => false]]], 'config.policy.cooldown is false'];

        yield 'block false' => [
            self::withCooldown([self::PERIOD => self::SEVEN_DAYS, 'block' => false, self::IGNORE => [self::OWN_VENDOR_PATTERN => self::REASON]]),
            'config.policy.cooldown.block is false',
        ];
    }

    #[Test]
    public function aMissingOwnVendorIgnoreIsReportedWithTheCorrectEntry(): void
    {
        $report = $this->inspect(self::withCooldown([self::PERIOD => self::SEVEN_DAYS]));

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('"acme/' . self::STAR . '"', $report->problems[0]);
        self::assertStringContainsString('"ignore": {"acme/' . self::STAR . '": "<why>"}', $report->problems[0]);
    }

    #[Test]
    public function aRootWithoutANameSkipsTheOwnVendorCheck(): void
    {
        $json = self::withCooldown([self::PERIOD => self::SEVEN_DAYS]);
        unset($json[self::NAME]);

        self::assertSame([], $this->inspect($json)->problems);
    }

    #[Test]
    public function aNonStringNameIsTreatedAsNoName(): void
    {
        $json             = self::withCooldown([self::PERIOD => self::SEVEN_DAYS]);
        $json[self::NAME] = [self::OWN_NAME];

        self::assertSame([], $this->inspect($json)->problems);
    }

    #[Test]
    public function anOwnVendorEntryWithoutAReasonIsReported(): void
    {
        foreach ([[self::OWN_VENDOR_PATTERN], [self::OWN_VENDOR_PATTERN => null], [self::OWN_VENDOR_PATTERN => '  ']] as $ignore) {
            $report = $this->withIgnore($ignore);

            self::assertCount(1, $report->problems);
            self::assertStringContainsString('no reason', $report->problems[0]);
            self::assertStringContainsString('"acme/' . self::STAR . '"', $report->problems[0]);
        }
    }

    #[Test]
    public function anOwnVendorEntryAsAnObjectNeedsAReasonAndMustApplyToBlocking(): void
    {
        $entry = fn (array $rule): CooldownReport => $this->withIgnore([self::OWN_VENDOR_PATTERN => $rule]);

        $good        = $entry(['reason' => self::REASON]);
        $rules       = $entry([['reason' => self::REASON]]);
        $noReason    = $entry(['constraint' => '^1.0']);
        $auditOnly   = $entry(['reason' => self::REASON, 'on-block' => false]);
        $constrained = $entry(['reason' => self::REASON, 'constraint' => '^1.0']);

        self::assertSame([], $good->problems);
        self::assertSame([], $rules->problems);
        self::assertCount(1, $noReason->problems);
        self::assertCount(1, $auditOnly->problems);
        self::assertCount(1, $constrained->problems);
        self::assertStringContainsString('no reason', $noReason->problems[0]);
        self::assertStringContainsString('does not apply when versions are blocked', $auditOnly->problems[0]);
        self::assertStringContainsString('limited to a version constraint', $constrained->problems[0]);
    }

    #[Test]
    public function anIgnoreShapeNobodyRecognisesIsAFindingByName(): void
    {
        foreach (['a string', 5, true] as $ignore) {
            $report = $this->inspect(self::withCooldown([self::PERIOD => self::SEVEN_DAYS, self::IGNORE => $ignore]));

            self::assertCount(1, $report->problems);
            self::assertStringContainsString('ignore has a shape this check does not recognise', $report->problems[0]);
        }

        $entry = $this->withIgnore([self::OWN_VENDOR_PATTERN => 5]);
        self::assertCount(1, $entry->problems);
        self::assertStringContainsString('shape this check does not recognise', $entry->problems[0]);
    }

    #[Test]
    public function aValidSecurityOverrideIsListedAndPasses(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => self::OVERRIDE_REASON]);

        self::assertSame([], $report->problems);
        self::assertSame([self::OVERRIDE_PACKAGE . ': CVE-2026-1234, fixed in 1.2.3, remove after 2026-11-20'], $report->overrides);
    }

    #[Test]
    public function aSecurityOverrideGivenAsAnObjectWithAReasonIsAccepted(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => ['reason' => self::OVERRIDE_REASON]]);

        self::assertSame([], $report->problems);
        self::assertCount(1, $report->overrides);
    }

    #[Test]
    public function everyAdvisoryIdFormIsAccepted(): void
    {
        foreach (['CVE-2026-1234', 'GHSA-abcd-1234-wxyz', 'PKSA-ab12-cd34-ef56'] as $advisory) {
            $reason = 'SECURITY ' . $advisory . ': fixed in v2.0.1-rc1; PR http://example.com/p/1; remove after 2026-12-31';

            self::assertSame([], $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => $reason])->problems, $advisory);
        }
    }

    #[Test]
    #[DataProvider('malformedReasons')]
    public function anOverrideWhoseReasonIsNotInTheRequiredFormIsReportedAndStillListed(mixed $reason): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => $reason]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('not in the required form', $report->problems[0]);
        self::assertStringContainsString('SECURITY <advisory id>: fixed in <version>; PR <url>; remove after <YYYY-MM-DD>', $report->problems[0]);
        self::assertCount(1, $report->overrides);
        self::assertStringContainsString(self::OVERRIDE_PACKAGE, $report->overrides[0]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedReasons(): iterable
    {
        yield 'free text' => ['needed urgently'];

        yield 'no security prefix' => ['CVE-2026-1234: fixed in 1.2.3; PR https://example.com/p/1; remove after 2026-11-20'];

        yield 'bad advisory id' => ['SECURITY BUG-1: fixed in 1.2.3; PR https://example.com/p/1; remove after 2026-11-20'];

        yield 'no version' => ['SECURITY CVE-2026-1234: fixed in ; PR https://example.com/p/1; remove after 2026-11-20'];

        yield 'pr not a url' => ['SECURITY CVE-2026-1234: fixed in 1.2.3; PR #7; remove after 2026-11-20'];

        yield 'no date' => ['SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/p/1'];

        yield 'trailing text' => ['SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/p/1; remove after 2026-11-20 maybe'];

        yield 'null' => [null];

        yield 'empty' => [''];
    }

    #[Test]
    public function anOverrideInTheListFormHasNoReasonAndIsReported(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('not in the required form', $report->problems[0]);
    }

    #[Test]
    public function anOverrideNamingAGlobIsReportedEvenWithAValidReason(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, 'thirdparty/' . self::STAR => self::OVERRIDE_REASON]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('is a glob', $report->problems[0]);
        self::assertStringContainsString('only an internal vendor may be exempted by a glob', $report->problems[0]);
        self::assertCount(1, $report->overrides);
    }

    #[Test]
    public function anExpiredOverrideIsReported(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => self::OVERRIDE_REASON], '2026-11-21');

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('2026-11-20 has passed', $report->problems[0]);
        self::assertStringContainsString('remove the entry', $report->problems[0]);
        self::assertCount(1, $report->overrides);
    }

    #[Test]
    public function anOverrideWhoseDateIsTodayIsNotExpired(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => self::OVERRIDE_REASON], '2026-11-20');

        self::assertSame([], $report->problems);
    }

    #[Test]
    public function anInvalidCalendarDateIsReported(): void
    {
        $reason = 'SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/p/1; remove after 2026-02-30';

        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => $reason]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('2026-02-30 is not a valid calendar date', $report->problems[0]);
    }

    #[Test]
    public function anInternalVendorGlobNeedsNoSecurityForm(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON], self::TODAY);

        self::assertSame([], $report->problems);
        self::assertSame([], $report->overrides);
    }

    #[Test]
    public function aConfiguredInternalVendorMustAlsoBeExemptedWithAReason(): void
    {
        $missing = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON], self::TODAY, 'internal');

        self::assertCount(1, $missing->problems);
        self::assertStringContainsString('"internal/' . self::STAR . '"', $missing->problems[0]);

        $present = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, 'internal/' . self::STAR => self::REASON], self::TODAY, 'internal');
        self::assertSame([], $present->problems);
        self::assertSame([], $present->overrides);

        $noReason = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, 'internal/' . self::STAR => ''], self::TODAY, 'internal');
        self::assertCount(1, $noReason->problems);
        self::assertStringContainsString('no reason', $noReason->problems[0]);
    }

    #[Test]
    public function aConfiguredInternalVendorIsCheckedWithoutARootName(): void
    {
        $json = self::withCooldown([self::PERIOD => self::SEVEN_DAYS]);
        unset($json[self::NAME]);

        $report = $this->inspect($json, self::MODERN, self::TODAY, 'internal');

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('"internal/' . self::STAR . '"', $report->problems[0]);
    }

    #[Test]
    public function theRootVendorListedAsInternalIsNotCheckedTwice(): void
    {
        $report = $this->withIgnore([], self::TODAY, 'acme');

        self::assertCount(1, $report->problems);
    }

    #[Test]
    public function anIgnoreEntryOfAnUnrecognisedShapeIsAnOverrideFindingByName(): void
    {
        $report = $this->withIgnore([self::OWN_VENDOR_PATTERN => self::REASON, self::OVERRIDE_PACKAGE => [['reason' => self::OVERRIDE_REASON]]]);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('shape this check does not recognise', $report->problems[0]);
    }

    /**
     * @param array<string, mixed> $json
     */
    #[Test]
    #[DataProvider('malformedShapes')]
    public function aMalformedConfigShapeIsAFindingByNameAndNeverAnError(array $json): void
    {
        $report = $this->inspect($json);

        self::assertCount(1, $report->problems);
        self::assertStringContainsString('has a shape this check does not recognise', $report->problems[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedShapes(): iterable
    {
        yield 'config a string' => [[self::NAME => self::OWN_NAME, self::CONFIG => 'nope']];

        yield 'config an integer' => [[self::NAME => self::OWN_NAME, self::CONFIG => 5]];

        yield 'policy a string' => [[self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => 'strict']]];

        yield 'cooldown a string' => [[self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => [self::COOLDOWN => self::SEVEN_DAYS]]]];
    }

    #[Test]
    public function severalFindingsAreAllReportedTogether(): void
    {
        $report = $this->inspect(self::withCooldown(['block' => false]), 'Composer version 2.10.0 2026-01-01');

        self::assertCount(4, $report->problems);
    }

    /**
     * @return array<string, mixed>
     */
    private function compliant(): array
    {
        return self::withCooldown([
            self::PERIOD => self::SEVEN_DAYS,
            self::IGNORE => [self::OWN_VENDOR_PATTERN => self::REASON],
        ]);
    }

    /**
     * @param array<string, mixed> $cooldown
     *
     * @return array<string, mixed>
     */
    private static function withCooldown(array $cooldown): array
    {
        return [self::NAME => self::OWN_NAME, self::CONFIG => [self::POLICY => [self::COOLDOWN => $cooldown]]];
    }

    /**
     * @param array<array-key, mixed> $json
     */
    private function inspect(array $json, ?string $version = self::MODERN, string $today = self::TODAY, string ...$internalVendors): CooldownReport
    {
        return new CooldownPolicyChecker()->check($json, $version, $today, ...$internalVendors);
    }

    /**
     * @param array<array-key, mixed> $ignore
     */
    private function withIgnore(array $ignore, string $today = self::TODAY, string ...$internalVendors): CooldownReport
    {
        return $this->inspect(self::withCooldown([self::PERIOD => self::SEVEN_DAYS, self::IGNORE => $ignore]), self::MODERN, $today, ...$internalVendors);
    }
}
