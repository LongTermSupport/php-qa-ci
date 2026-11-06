<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness\Dto;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\OutdatedPackageDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A locked package `composer outdated` lists.
 *
 * @internal
 */
#[CoversClass(OutdatedPackageDto::class)]
#[Small]
final class OutdatedPackageDtoTest extends TestCase
{
    #[Test]
    public function itHoldsTheNameAndBothVersionsAsGiven(): void
    {
        $package = new OutdatedPackageDto('acme/widgets', 'v0.2.1', 'v0.3.0');

        self::assertSame('acme/widgets', $package->name);
        self::assertSame('v0.2.1', $package->version);
        self::assertSame('v0.3.0', $package->latest);
    }
}
