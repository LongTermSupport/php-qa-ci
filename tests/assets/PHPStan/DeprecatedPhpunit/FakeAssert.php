<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit;

/**
 * Fixture base mimicking PHPUnit's Assert: a deprecated + a modern method, in
 * both instance and static form. Self-contained so the rule test does not depend
 * on which methods the installed PHPUnit deprecates.
 *
 * @internal
 */
class FakeAssert
{
    /**
     * @deprecated use modernStaticAssert() instead
     */
    public static function legacyStaticAssert(): void
    {
    }

    public static function modernStaticAssert(): void
    {
    }

    /**
     * @deprecated use modernAssert() instead
     */
    public function legacyAssert(): void
    {
    }

    public function modernAssert(): void
    {
    }
}
