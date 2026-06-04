<?php

declare(strict_types=1);

namespace Fixture\WithAttribute;

/**
 * Fixture: a project that DOES use #[\SensitiveParameter] somewhere in src/.
 * The usage scanner must find this and report success (count >= 1).
 */
final class Authenticator
{
    public function login(string $username, #[\SensitiveParameter] string $password): bool
    {
        return '' !== $username && '' !== $password;
    }
}
