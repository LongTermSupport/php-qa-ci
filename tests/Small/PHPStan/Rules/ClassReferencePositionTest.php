<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassReferencePosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the positions {@see ClassReferencePosition} distinguishes: the rule gives each a
 * message of its own, so a position added or removed needs a message added or removed.
 *
 * @internal
 */
#[CoversClass(ClassReferencePosition::class)]
#[Small]
final class ClassReferencePositionTest extends TestCase
{
    #[Test]
    public function thePositionsAreTheFourThatCanLoadAClassAndTheOneThatCannot(): void
    {
        self::assertSame(
            ['Loading', 'ClassName', 'Attribute', 'MethodSignature', 'Inert'],
            array_map(static fn (ClassReferencePosition $position): string => $position->name, ClassReferencePosition::cases()),
        );
    }
}
