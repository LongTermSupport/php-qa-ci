<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit;

/**
 * Fixture analysed by the rule: a plain class (NOT a PHPUnit test) that happens
 * to define and call a method whose name collides with a deprecated PHPUnit
 * method. The rule must NOT flag it — the call target is not a configured
 * PHPUnit type. Guards against false positives from name-only matching.
 *
 * @internal
 */
final class NotATest
{
    public function legacyExpect(): void
    {
    }

    public function run(): void
    {
        $this->legacyExpect();
    }
}
