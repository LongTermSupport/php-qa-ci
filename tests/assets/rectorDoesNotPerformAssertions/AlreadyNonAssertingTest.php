<?php

declare(strict_types=1);

namespace Fixture\DoesNotPerformAssertions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A smoke test that genuinely asserts nothing and already says so: the attribute is correct
 * here, and rector-phpunit.php must leave it in place, once.
 *
 * @internal
 */
#[CoversNothing]
final class AlreadyNonAssertingTest extends TestCase
{
    #[Test]
    #[DoesNotPerformAssertions]
    public function itConstructsWithoutThrowing(): void
    {
        new \ArrayObject([]);
    }
}
