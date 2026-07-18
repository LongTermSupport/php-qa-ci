<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagDetector;
use LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagVerdictEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision tests for {@see ApiOrInternalTagDetector}: given whether a public
 * class-like carries `@api` / `@internal`, decide whether it is classified,
 * unclassified (Missing) or contradictory (Both). Framework-free — no PHPStan
 * Scope / RuleTestCase needed.
 *
 * The detector is now PURE tag logic: WHETHER to enforce at all (the package-type
 * gate plus the auto/always/never override) is decided upstream by
 * {@see \LTS\PHPQA\PackageType\ProjectComposerTypeReader::enforcesApiSurface()} and
 * tested there — so there are no `type` cases here any more.
 *
 * @internal
 */
#[CoversClass(ApiOrInternalTagDetector::class)]
#[Small]
final class ApiOrInternalTagDetectorTest extends TestCase
{
    private ApiOrInternalTagDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ApiOrInternalTagDetector();
    }

    #[Test]
    #[DataProvider('cases')]
    public function itClassifiesByTagPresence(
        bool $hasApi,
        bool $hasInternal,
        ApiOrInternalTagVerdictEnum $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->detector->classify($hasApi, $hasInternal),
        );
    }

    /**
     * @return iterable<string, array{bool, bool, ApiOrInternalTagVerdictEnum}>
     */
    public static function cases(): iterable
    {
        // A classified public class-like carries exactly one of @api (supported
        // contract) or @internal (may change without a major bump).
        yield '@api only — classified public'         => [true, false, ApiOrInternalTagVerdictEnum::Ok];
        yield '@internal only — classified internal'  => [false, true, ApiOrInternalTagVerdictEnum::Ok];
        yield 'neither — must classify'               => [false, false, ApiOrInternalTagVerdictEnum::Missing];
        yield 'both — contradictory'                  => [true, true, ApiOrInternalTagVerdictEnum::Both];
    }
}
