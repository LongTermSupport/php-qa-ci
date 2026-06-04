<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter;

/**
 * Fixture for RequireSensitiveParameterAttributeRule.
 *
 * Each method exercises a single branch of the rule. Line numbers are
 * asserted in RequireSensitiveParameterAttributeRuleTest, so DO NOT
 * reformat or reorder this file without updating the test expectations.
 */
final class CredentialParams
{
    // Line 19: plaintext password without the attribute — MUST be flagged.
    public function loginMissing(string $password): bool
    {
        return '' !== $password;
    }

    // Plaintext password WITH the attribute — OK, no error.
    public function loginOk(#[\SensitiveParameter] string $password): bool
    {
        return '' !== $password;
    }

    // Already-hashed value — ignored via the "hashed" ignore-substring.
    public function verify(string $hashedPassword): bool
    {
        return '' !== $hashedPassword;
    }

    // Object-typed credential — ignored because it is not a plaintext value.
    public function authenticate(Credentials $credential): bool
    {
        return $credential->isValid();
    }

    // Non-credential name — ignored, never matches a name pattern.
    public function notify(string $email): bool
    {
        return '' !== $email;
    }

    // Constructor-promoted secret WITH the attribute — OK, no error.
    public function __construct(#[\SensitiveParameter] private readonly string $secret = '')
    {
    }

    public function getSecret(): string
    {
        return $this->secret;
    }
}
