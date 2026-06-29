<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit;

/**
 * Fixture for DeprecatedPhpunitMethodDetector: a class exposing methods
 * deprecated by each supported marker (a @deprecated docblock and the native
 * PHP 8.4 #[\Deprecated] attribute) plus a non-deprecated control method.
 *
 * Using a fixture (rather than the real installed PHPUnit) keeps the detector's
 * unit test deterministic across PHPUnit versions — different PHPUnit releases
 * deprecate different methods.
 *
 * @internal
 */
class LegacyApi
{
    /**
     * @deprecated use modernViaDocblock() instead
     */
    public function legacyViaDocblock(): void
    {
    }

    #[\Deprecated('use modernViaAttribute() instead')]
    public function legacyViaAttribute(): void
    {
    }

    public function modernMethod(): void
    {
    }
}
