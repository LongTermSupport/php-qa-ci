<?php

declare(strict_types=1);

namespace ArkitectFixture;

// Violates the default *Enum suffix rule (should be StatusEnum). Exercises the
// IsEnum default-tier rule (node-kind match, no autoloader needed).
enum Status
{
    case Active;
    case Inactive;
}
