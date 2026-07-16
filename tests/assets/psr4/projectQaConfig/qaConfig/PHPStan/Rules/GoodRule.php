<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

/**
 * A real, correctly-namespaced custom rule class under the `QaConfig\` PSR-4
 * root. It proves the config-file exclusion does NOT blanket-skip the qaConfig
 * tree: genuine rule classes are still validated (and this one passes).
 */
final class GoodRule
{
}
