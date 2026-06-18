<?php

declare(strict_types=1);

namespace ArkitectFixture;

// Violates the default *Trait suffix rule (should be HelperTrait). Exercises the
// IsTrait default-tier rule (node-kind match, no autoloader needed).
trait Helper
{
}
