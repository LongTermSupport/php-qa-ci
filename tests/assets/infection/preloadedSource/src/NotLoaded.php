<?php

declare(strict_types=1);

namespace PreloadedSource;

/** Never loaded by the autoloader, so Infection swaps it as usual. */
final readonly class NotLoaded
{
    public function answer(): int
    {
        return 7;
    }
}
