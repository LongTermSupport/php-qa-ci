<?php

declare(strict_types=1);

namespace Fixture\Multiple;

/**
 * Fixture: second (alphabetically) source file, carrying TWO distinct
 * #[\SensitiveParameter] usages so the scanner must report both lines from a
 * single file. See {@see AlphaService} for the aggregation rationale.
 */
final class ZuluHandler
{
    public function authenticate(string $user, #[\SensitiveParameter] string $password): bool
    {
        return '' !== $user && '' !== $password;
    }

    public function rotate(#[\SensitiveParameter] string $token, string $reason): bool
    {
        return '' !== $token && '' !== $reason;
    }
}
