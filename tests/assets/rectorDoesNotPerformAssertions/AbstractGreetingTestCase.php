<?php

declare(strict_types=1);

namespace Fixture\DoesNotPerformAssertions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An abstract parent whose test methods assert. A subclass supplies the data by overriding a
 * method with #[DataProvider] and delegating straight back here, so every assertion the
 * subclass's test performs is made in this class.
 *
 * @internal
 */
abstract class AbstractGreetingTestCase extends TestCase
{
    public function testGreetsByName(string $name): void
    {
        self::assertSame('Hello ' . $name, 'Hello ' . $name);
    }

    #[Test]
    public function itCountsTheLetters(string $name, int $letters): void
    {
        self::assertSame($letters, \strlen($name));
    }
}
