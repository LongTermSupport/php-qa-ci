<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit;

/**
 * Fixture analysed by the rule: a "test" (extends the fake TestCase) that calls
 * deprecated and modern methods, as instance and static calls. The rule must
 * flag exactly the three deprecated calls.
 *
 * @internal
 */
final class ConsumerTest extends FakeTestCase
{
    public function testStuff(): void
    {
        $this->legacyExpect();
        $this->modernExpect();
        $this->legacyAssert();
        $this->modernAssert();
        self::legacyStaticAssert();
        self::modernStaticAssert();
    }
}
