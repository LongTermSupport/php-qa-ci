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
 * Pure decision tests for {@see ApiOrInternalTagDetector}: given the project's
 * composer `type` and whether a public class-like carries `@api` / `@internal`,
 * decide whether (and how) it violates the "a library must classify its public
 * surface" convention. Framework-free — no PHPStan Scope / RuleTestCase needed.
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
    public function itClassifiesByProjectTypeAndTagPresence(
        string $projectType,
        bool $hasApi,
        bool $hasInternal,
        ApiOrInternalTagVerdictEnum $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->detector->classify($projectType, $hasApi, $hasInternal),
        );
    }

    /**
     * @return iterable<string, array{string, bool, bool, ApiOrInternalTagVerdictEnum}>
     */
    public static function cases(): iterable
    {
        // A library MUST classify every public class-like as exactly one of
        // @api (supported contract) or @internal (may change without a major bump).
        yield 'library, @api only — classified public'      => ['library', true, false, ApiOrInternalTagVerdictEnum::Ok];
        yield 'library, @internal only — classified internal' => ['library', false, true, ApiOrInternalTagVerdictEnum::Ok];
        yield 'library, neither — must classify'             => ['library', false, false, ApiOrInternalTagVerdictEnum::Missing];
        yield 'library, both — contradictory'                => ['library', true, true, ApiOrInternalTagVerdictEnum::Both];

        // An application (type: project) has no consumer-facing API surface, so the
        // classification is NOT required — the rule no-ops regardless of tags.
        yield 'project, neither — not enforced'              => ['project', false, false, ApiOrInternalTagVerdictEnum::Ok];
        yield 'project, both — not enforced'                 => ['project', true, true, ApiOrInternalTagVerdictEnum::Ok];
        yield 'project, @api only — not enforced'            => ['project', true, false, ApiOrInternalTagVerdictEnum::Ok];

        // Any other declared type (metapackage, composer-plugin, …) is treated like
        // a non-library: not enforced by this rule.
        yield 'metapackage — not enforced'                   => ['metapackage', false, false, ApiOrInternalTagVerdictEnum::Ok];
        yield 'composer-plugin — not enforced'               => ['composer-plugin', false, false, ApiOrInternalTagVerdictEnum::Ok];
    }

    #[Test]
    public function theTypeComparisonIsCaseInsensitiveAndTrimmed(): void
    {
        // composer `type` is author-typed free text; normalise so " Library " and
        // "LIBRARY" are still treated as a library (and still enforced).
        self::assertSame(
            ApiOrInternalTagVerdictEnum::Missing,
            $this->detector->classify('  LiBrArY  ', false, false),
        );
    }
}
