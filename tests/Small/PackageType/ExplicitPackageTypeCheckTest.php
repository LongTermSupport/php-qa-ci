<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\ExplicitPackageTypeCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the thin pipeline runner around {@see \LTS\PHPQA\PackageType\ExplicitPackageTypeDetector}:
 * it maps the pure verdict to a process exit code (0 = OK, 1 = build failure) and
 * prints either a confirmation or the actionable guidance. The exhaustive
 * type-matrix lives in ExplicitPackageTypeDetectorTest; here we only assert the
 * glue (exit code + output).
 *
 * @internal
 */
#[CoversClass(ExplicitPackageTypeCheck::class)]
#[Small]
final class ExplicitPackageTypeCheckTest extends TestCase
{
    #[Test]
    public function itReturnsZeroAndConfirmsForAnExplicitType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/explicit package type/');
        self::assertSame(0, $check->run(['type' => 'library']));
    }

    #[Test]
    public function itReturnsOneAndPrintsGuidanceForAnUndeclaredType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/type/');
        self::assertSame(1, $check->run(['name' => 'acme/widget']));
    }

    #[Test]
    public function itReturnsOneForABlankType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/./');
        self::assertSame(1, $check->run(['type' => '   ']));
    }
}
