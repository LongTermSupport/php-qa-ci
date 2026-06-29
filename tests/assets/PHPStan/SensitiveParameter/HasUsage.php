<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter;

/**
 * Fixture for RequireSensitiveParameterUsageRule: contains at least one
 * #[\SensitiveParameter] occurrence so the codebase-wide collector records
 * a non-zero count and the usage rule does not fire.
 */
final class HasUsage
{
    public function login(#[\SensitiveParameter] string $password): bool
    {
        return '' !== $password;
    }
}
