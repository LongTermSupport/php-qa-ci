<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit;

/**
 * Fixture mimicking PHPUnit's TestCase (extends the fake Assert), adding a
 * deprecated + a modern expectation method of its own.
 *
 * @internal
 */
class FakeTestCase extends FakeAssert
{
    /**
     * @deprecated use modernExpect() instead
     */
    protected function legacyExpect(): void
    {
    }

    protected function modernExpect(): void
    {
    }
}
