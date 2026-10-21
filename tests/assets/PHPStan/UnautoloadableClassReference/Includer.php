<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

/**
 * Loads the declaring file itself, so its reference never depends on another file
 * having been loaded first.
 */
final class Includer
{
    public function make(): StrandedHelper
    {
        require_once __DIR__ . '/HostTest.php';

        return new StrandedHelper();
    }
}
