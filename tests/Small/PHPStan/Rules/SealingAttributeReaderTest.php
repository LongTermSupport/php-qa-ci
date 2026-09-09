<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Attribute\FactorySealedBy;
use LTS\PHPQA\PHPStan\Rules\SealingAttributeReader;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\CustomSeal;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\SealedByCustomAttribute;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\SealedByPackageAttribute;
use LTS\PHPQA\Tests\Assets\PHPStan\FactorySealed\UnsealedClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the reflection seam that resolves a class's authorised factory from
 * its sealing attribute. Uses real, autoloadable fixture classes so the native
 * reflection path is exercised end-to-end (the reader reads the attribute's
 * first argument, never instantiating the attribute — so it works for any
 * project-defined sealing attribute, not just the package's reference one).
 *
 * @internal
 */
#[CoversClass(SealingAttributeReader::class)]
#[Small]
final class SealingAttributeReaderTest extends TestCase
{
    private SealingAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new SealingAttributeReader();
    }

    public function testItResolvesTheFactoryFromThePackageReferenceAttribute(): void
    {
        self::assertSame(
            'Acme\Widget\WidgetFactory',
            $this->reader->factoryFor(SealedByPackageAttribute::class, FactorySealedBy::class),
        );
    }

    public function testItResolvesTheFactoryFromAProjectDefinedAttribute(): void
    {
        self::assertSame(
            'Acme\Order\OrderFactory',
            $this->reader->factoryFor(SealedByCustomAttribute::class, CustomSeal::class),
        );
    }

    public function testItReturnsNullWhenTheClassHasNoConfiguredSealingAttribute(): void
    {
        self::assertNull(
            $this->reader->factoryFor(UnsealedClass::class, FactorySealedBy::class, CustomSeal::class),
        );
    }

    public function testItIgnoresASealingAttributeThatIsNotInTheConfiguredList(): void
    {
        // SealedByCustomAttribute IS sealed, but only by CustomSeal — which is
        // not in the list we pass here, so the reader must not pick it up.
        self::assertNull(
            $this->reader->factoryFor(SealedByCustomAttribute::class, FactorySealedBy::class),
        );
    }

    public function testItReturnsNullForAnUnknownClass(): void
    {
        self::assertNull(
            $this->reader->factoryFor('Acme\Nope\DoesNotExist', FactorySealedBy::class),
        );
    }
}
