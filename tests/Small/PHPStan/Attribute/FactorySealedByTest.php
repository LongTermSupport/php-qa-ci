<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Attribute;

use Attribute;
use LTS\PHPQA\PHPStan\Attribute\FactorySealedBy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(FactorySealedBy::class)]
#[Small]
final class FactorySealedByTest extends TestCase
{
    public function testItExposesTheAuthorisedFactory(): void
    {
        // Any real class-string serves as the "factory" for this VO-level test;
        // \stdClass keeps it a valid class-string (the param's declared type).
        $attribute = new FactorySealedBy(stdClass::class);

        self::assertSame(stdClass::class, $attribute->factory);
    }

    public function testItTargetsClassesOnly(): void
    {
        $reflection = new ReflectionClass(FactorySealedBy::class);

        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);
        self::assertSame(Attribute::TARGET_CLASS, $attributes[0]->newInstance()->flags);
    }
}
