<?php

declare(strict_types=1);

namespace Fixture\DoesNotPerformAssertions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Positive control: rector-phpunit.php must rewrite $this->assertTrue() to self::assertTrue(),
 * which proves the run loaded the configuration and reached the fixtures.
 *
 * @internal
 */
#[CoversNothing]
final class PositiveControlTest extends TestCase
{
    #[Test]
    public function itAsserts(): void
    {
        $this->assertTrue(true);
    }
}
