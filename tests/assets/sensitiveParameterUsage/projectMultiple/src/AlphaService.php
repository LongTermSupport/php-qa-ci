<?php

declare(strict_types=1);

namespace Fixture\Multiple;

/**
 * Fixture: first (alphabetically) source file, carrying a SINGLE
 * #[\SensitiveParameter] usage. Combined with ZuluHandler (two usages) this lets
 * the scanner test pin cross-file aggregation, per-file multi-attribute counting
 * and the sorted, deterministic ordering of the reported locations.
 */
final class AlphaService
{
    public function connect(string $dsn, #[\SensitiveParameter] string $secret): bool
    {
        return '' !== $dsn && '' !== $secret;
    }
}
