<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DevNamespace\DevTools;

/**
 * Neither the `DevTools` segment nor the `DevModeSwitch` class name is a `Dev`
 * SEGMENT, so neither declares the code dev-only and neither carries the hazard:
 * shipping a class about development mode is not the same as shipping the
 * maintainer's tooling. The rule must stay quiet on both.
 */
final class DevModeSwitch
{
    public function enabled(): bool
    {
        return false;
    }
}
