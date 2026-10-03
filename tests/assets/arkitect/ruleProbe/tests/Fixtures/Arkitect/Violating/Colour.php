<?php

declare(strict_types=1);

namespace ArkitectProbe\Fixture;

// Violates the default tier: an enum without the Enum suffix.
enum Colour
{
    case Red;
}
