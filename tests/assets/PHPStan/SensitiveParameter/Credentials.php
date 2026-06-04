<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter;

/**
 * Object-typed credential value used by the CredentialParams fixture to prove
 * that object-typed parameters named like credentials are NOT flagged.
 */
final readonly class Credentials
{
    public function __construct(private string $token = '')
    {
    }

    public function isValid(): bool
    {
        return '' !== $this->token;
    }
}
