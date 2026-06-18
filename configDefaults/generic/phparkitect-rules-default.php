<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — GENERIC DEFAULT ruleset (the "rules-default" tier).
 *
 * Generic, safe-everywhere BallicomDev naming conventions. This tier is applied
 * BY DEFAULT to every consuming project (the shipped configDefaults entry
 * config `phparkitect.php` adds it to the detected source dir), exactly the way
 * rules-default.neon is the PHPStan baseline. Keep it conservative — anything
 * that is not safe across every project belongs in the optional tiers
 * (phparkitect-rules-optional.php, phparkitect-rules-optional-symfony.php).
 *
 * This file returns a list of ArchRule objects; it is NOT a runnable config (no
 * ClassSet). Projects compose it from qaConfig/phparkitect.php:
 *
 *   $default = require getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
 *   $config->add($classSet, ...$default, ...$projectRules);   // EXTEND
 *
 * To CUSTOMISE the baseline itself, copy this file to
 * qaConfig/phparkitect-rules-default.php and edit it — configPath resolves the
 * project copy first, and both the entry config and the
 * PHPQACI_ARKITECT_RULES_DEFAULT env var follow it.
 *
 * @return list<\Arkitect\Rules\ArchRule>
 */

use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\IsEnum;
use Arkitect\Expression\ForClasses\IsInterface;
use Arkitect\Expression\ForClasses\IsTrait;
use Arkitect\Rules\Rule;

// These rules match on AST node KIND (interface/enum/trait keyword) and so do
// NOT force PHPArkitect to resolve class ancestry — that keeps them robust even
// when a project references a base type that is not in the autoloader. Rules
// that DO resolve ancestry (IsA/Extend/Implement, e.g. the *Exception
// convention) live in the optional tier, because they require a complete
// autoloader and are therefore not safe-everywhere.
return [
    // Interfaces / enums / traits carry their kind in the suffix
    // (parity with php-qa-ci's RequireTypeSuffixRule).
    Rule::allClasses()
        ->that(new IsInterface())
        ->should(new HaveNameMatching('*Interface'))
        ->because('an Interface suffix makes the symbol kind obvious at every use site'),
    Rule::allClasses()
        ->that(new IsEnum())
        ->should(new HaveNameMatching('*Enum'))
        ->because('an Enum suffix makes the symbol kind obvious at every use site'),
    Rule::allClasses()
        ->that(new IsTrait())
        ->should(new HaveNameMatching('*Trait'))
        ->because('a Trait suffix makes the symbol kind obvious at every use site'),
];
