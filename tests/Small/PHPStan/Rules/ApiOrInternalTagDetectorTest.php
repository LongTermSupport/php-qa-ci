<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagDetector;
use LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagVerdict;
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

    /**
     * @return iterable<string, array{string, bool, bool, ApiOrInternalTagVerdict}>
     */
    public static function cases(): iterable
    {
        // A library MUST classify every public class-like as exactly one of
        // @api (supported contract) or @internal (may change without a major bump).
        yield 'library, @api only — classified public'      => ['library', true, false, ApiOrInternalTagVerdict::Ok];
        yield 'library, @internal only — classified internal' => ['library', false, true, ApiOrInternalTagVerdict::Ok];
        yield 'library, neither — must classify'             => ['library', false, false, ApiOrInternalTagVerdict::Missing];
        yield 'library, both — contradictory'                => ['library', true, true, ApiOrInternalTagVerdict::Both];

        // An application (type: project) has no consumer-facing API surface, so the
        // classification is NOT required — the rule no-ops regardless of tags.
        yield 'project, neither — not enforced'              => ['project', false, false, ApiOrInternalTagVerdict::Ok];
        yield 'project, both — not enforced'                 => ['project', true, true, ApiOrInternalTagVerdict::Ok];
        yield 'project, @api only — not enforced'            => ['project', true, false, ApiOrInternalTagVerdict::Ok];

        // Any other declared type (metapackage, composer-plugin, …) is treated like
        // a non-library: not enforced by this rule.
        yield 'metapackage — not enforced'                   => ['metapackage', false, false, ApiOrInternalTagVerdict::Ok];
        yield 'composer-plugin — not enforced'               => ['composer-plugin', false, false, ApiOrInternalTagVerdict::Ok];
    }

    #[Test]
    #[DataProvider('cases')]
    public function itClassifiesByProjectTypeAndTagPresence(
        string $projectType,
        bool $hasApi,
        bool $hasInternal,
        ApiOrInternalTagVerdict $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->detector->classify($projectType, $hasApi, $hasInternal),
        );
    }

    #[Test]
    public function theTypeComparisonIsCaseInsensitiveAndTrimmed(): void
    {
        // composer `type` is author-typed free text; normalise so " Library " and
        // "LIBRARY" are still treated as a library (and still enforced).
        self::assertSame(
            ApiOrInternalTagVerdict::Missing,
            $this->detector->classify('  LiBrArY  ', false, false),
        );
    }
}
