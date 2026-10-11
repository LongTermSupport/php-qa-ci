<?php

declare(strict_types=1);

namespace PreloadedSource;

/** Loaded by files-entry.php before any bootstrap, so Infection can never swap it for a mutant. */
final readonly class Preloaded
{
    public function answer(): int
    {
        return 42;
    }
}
