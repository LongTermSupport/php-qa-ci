<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\DeprecatedPhpunitMethodDetector;
use LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit\LegacyApi;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dynamic discovery of deprecated methods. Discovery is asserted
 * against a fixture ({@see LegacyApi}) so the test is deterministic regardless of
 * which methods the installed PHPUnit happens to deprecate; a final assertion
 * confirms discovery DOES run against the real installed PHPUnit.
 *
 * @internal
 */
#[CoversClass(DeprecatedPhpunitMethodDetector::class)]
#[Small]
final class DeprecatedPhpunitMethodDetectorTest extends TestCase
{
    public function testItDiscoversMethodsDeprecatedViaDocblock(): void
    {
        $detector = new DeprecatedPhpunitMethodDetector([LegacyApi::class]);

        self::assertTrue($detector->isDeprecated('legacyViaDocblock'));
    }

    public function testItDiscoversMethodsDeprecatedViaTheNativeAttribute(): void
    {
        $detector = new DeprecatedPhpunitMethodDetector([LegacyApi::class]);

        self::assertTrue($detector->isDeprecated('legacyViaAttribute'));
    }

    public function testItDoesNotFlagNonDeprecatedMethods(): void
    {
        $detector = new DeprecatedPhpunitMethodDetector([LegacyApi::class]);

        self::assertFalse($detector->isDeprecated('modernMethod'));
    }

    public function testDeprecatedMethodsReturnsTheDiscoveredSet(): void
    {
        $detector = new DeprecatedPhpunitMethodDetector([LegacyApi::class]);

        $methods = $detector->deprecatedMethods();

        self::assertArrayHasKey('legacyViaDocblock', $methods);
        self::assertArrayHasKey('legacyViaAttribute', $methods);
        self::assertArrayNotHasKey('modernMethod', $methods);
    }

    public function testItIgnoresClassesThatDoNotExist(): void
    {
        $detector = new DeprecatedPhpunitMethodDetector(['LTS\PHPQA\Nope\DoesNotExist']);

        self::assertSame([], $detector->deprecatedMethods());
    }

    public function testItDiscoversDeprecationsFromTheRealInstalledPhpunit(): void
    {
        // Proves discovery runs dynamically against the actual PHPUnit install
        // (which methods are deprecated varies by version, so we only assert the
        // set is non-empty — the specific replacements are a per-call concern).
        $detector = new DeprecatedPhpunitMethodDetector([TestCase::class, Assert::class]);

        self::assertNotSame([], $detector->deprecatedMethods());
    }
}
