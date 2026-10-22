<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassReferencePositionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;
use ReflectionEnumUnitCase;

/**
 * Pins the positions {@see ClassReferencePositionEnum} distinguishes: the rule gives each a
 * message of its own, so a position added or removed needs a message added or removed.
 *
 * @internal
 */
#[CoversClass(ClassReferencePositionEnum::class)]
#[Small]
final class ClassReferencePositionEnumTest extends TestCase
{
    #[Test]
    public function thePositionsAreTheFourThatCanLoadAClassAndTheOneThatCannot(): void
    {
        $cases = new ReflectionEnum(ClassReferencePositionEnum::class)->getCases();

        self::assertSame(
            ['Loading', 'ClassName', 'Attribute', 'MethodSignature', 'Inert'],
            array_map(static fn (ReflectionEnumUnitCase $case): string => $case->getName(), $cases),
        );
    }
}
