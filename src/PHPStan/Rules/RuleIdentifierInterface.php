<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Shared identifier prefix for all PHP-QA-CI bundled PHPStan rules.
 *
 * Each rule defines its own IDENTIFIER constant by concatenating
 * self::PREFIX with a rule-specific suffix. This keeps the top-level
 * "phpqaci" namespace defined in exactly one place.
 */
interface RuleIdentifierInterface
{
    public const string PREFIX = 'phpqaci';
}
