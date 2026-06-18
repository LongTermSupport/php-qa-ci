<?php

declare(strict_types=1);

/**
 * PHPArkitect entry config — PROJECT OVERRIDE template.
 *
 * PHPArkitect enforces *structural* rules that PHPStan expresses awkwardly:
 * class-naming conventions, namespace layering, and dependency direction.
 *
 * You do NOT need this file to get the basics: php-qa-ci applies a generic-safe
 * baseline (Exception/Interface/Enum/Trait suffixes) to every project by
 * default. Add this file only when you want to EXTEND that baseline, OPT IN to
 * extra shipped tiers, or add PROJECT-BESPOKE rules.
 *
 *   cp vendor/lts/php-qa-ci/templates/qaConfig-phparkitect.php qaConfig/phparkitect.php
 *   vendor/bin/qa -t arch
 *
 * RULE TIERS (each resolved + exported by the pipeline; override any by dropping
 * your own copy in qaConfig/, e.g. qaConfig/phparkitect-rules-default.php):
 *   PHPQACI_ARKITECT_RULES_DEFAULT           generic-safe baseline (on by default)
 *   PHPQACI_ARKITECT_RULES_OPTIONAL          stricter generic, opt-in
 *   PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY  Symfony-specific, opt-in
 *
 * COMPOSE: default + (optional tiers you opt into) + your bespoke rules.
 * REPLACE:  pass only your own rules (don't require the default tier).
 *
 * Paths are defined HERE: ClassSet::fromDir(...). __DIR__ is qaConfig/, so
 * '/../src' is the project src/. The pipeline passes --autoload for you.
 *
 * Full rule catalogue: https://github.com/phparkitect/arkitect
 */

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    // Adjust to your project. Exclude generated code (cannot be renamed).
    $rootNamespace = 'App';
    $classSet      = ClassSet::fromDir(__DIR__ . '/../src')->excludePath('Generated');

    $load = static fn (string $envVar): array => ($p = getenv($envVar)) && \is_file($p) ? require $p : [];

    // EXTEND the generic-safe baseline. (Drop this line to REPLACE it.)
    $defaultRules = $load('PHPQACI_ARKITECT_RULES_DEFAULT');

    // OPT IN to extra shipped tiers — uncomment as desired:
    // $optionalRules = $load('PHPQACI_ARKITECT_RULES_OPTIONAL');
    // $symfonyRules  = $load('PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY');

    // PROJECT-BESPOKE rules (stricter than the defaults):
    $projectRules = [
        // Example — classes in App\Controller end with *Controller.
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces($rootNamespace . '\\Controller'))
            ->should(new HaveNameMatching('*Controller'))
            ->because('controllers must be named consistently'),
    ];

    $config->add(
        $classSet,
        ...$defaultRules,
        // ...$optionalRules,
        // ...$symfonyRules,
        ...$projectRules,
    );
};
