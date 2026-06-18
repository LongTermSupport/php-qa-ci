<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — OPTIONAL generic ruleset (the "rules-optional" tier).
 *
 * Stricter, opinionated naming conventions that are NOT safe to force on every
 * project, so they are OPT-IN — a project enables them by composing this file
 * into its qaConfig/phparkitect.php (mirrors rules-optional.neon for PHPStan):
 *
 *   $default  = require getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
 *   $optional = require getenv('PHPQACI_ARKITECT_RULES_OPTIONAL');
 *   $config->add($classSet, ...$default, ...$optional, ...$projectRules);
 *
 * This is a curated set meant to grow. Keep each rule generic (no project
 * namespaces); project-specific rules belong in the project's own config.
 *
 * NOTE: the *Exception rule below keys off `IsA(\Throwable)`, which makes
 * PHPArkitect resolve each class's ancestry — so it requires a COMPLETE
 * autoloader (a class implementing/extending a type missing from
 * vendor/autoload.php aborts the run). That fragility is exactly why it is
 * opt-in here rather than in the safe-everywhere default tier.
 *
 * @return list<\Arkitect\Rules\ArchRule>
 */

use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\IsA;
use Arkitect\Expression\ForClasses\IsAbstract;
use Arkitect\Rules\Rule;

return [
    // Every \Throwable is suffixed *Exception, so throw/catch sites and
    // signatures are unambiguous. (Ancestry-resolving — see NOTE above.)
    Rule::allClasses()
        ->that(new IsA(\Throwable::class))
        ->should(new HaveNameMatching('*Exception'))
        ->because('a consistent Exception suffix makes throw/catch sites and signatures unambiguous'),

    // Abstract classes are prefixed Abstract*, so the abstract-ness of a base
    // type is obvious at the use site without opening the file.
    Rule::allClasses()
        ->that(new IsAbstract())
        ->should(new HaveNameMatching('Abstract*'))
        ->because('an Abstract prefix signals a non-instantiable base type at every use site'),
];
