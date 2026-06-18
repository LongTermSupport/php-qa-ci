<?php

declare(strict_types=1);

/**
 * PHPArkitect entry config — PROJECT OVERRIDE template.
 *
 * You do NOT need this file to get the basics: php-qa-ci applies an on-by-default
 * baseline of Interface/Enum/Trait name suffixes to every project. The
 * `*Exception` suffix is NOT in that baseline — it lives in the opt-in optional
 * tier (PHPQACI_ARKITECT_RULES_OPTIONAL). Add this file only to EXTEND the
 * baseline, OPT IN to extra shipped tiers, or add PROJECT-BESPOKE rules.
 *
 *   cp vendor/lts/php-qa-ci/templates/qaConfig-phparkitect.php qaConfig/phparkitect.php
 *   vendor/bin/qa -t arch
 *
 * Shipped tier env vars (exported by the pipeline; load via the $tier helper below):
 *   PHPQACI_ARKITECT_RULES_DEFAULT           on-by-default baseline
 *   PHPQACI_ARKITECT_RULES_OPTIONAL          stricter generic, opt-in
 *   PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY  Symfony-specific, opt-in
 *
 * Full usage (tiers, extend/replace, disable):
 *   [README "PHPArkitect (architecture rules)"](../README.md#phparkitect-architecture-rules)
 * Where a rule belongs (arkitect vs PHPStan):
 *   [README "Where does a rule belong"](../README.md#where-does-a-rule-belong--phparkitect-or-phpstan)
 *
 * Paths are defined HERE: ClassSet::fromDir(...). __DIR__ is qaConfig/, so
 * '/../src' is the project src/. The pipeline passes --autoload for you.
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

    // Resolve a shipped tier from the path the pipeline exports. Env var only —
    // no hard-coded vendor layout. Run via `vendor/bin/qa -t arch`; a bare
    // `phparkitect check` is unsupported and fails loudly rather than silently
    // applying zero rules.
    $tier = static function (string $envVar): array {
        $path = getenv($envVar);
        if (false === $path || '' === $path) {
            throw new \RuntimeException(\sprintf(
                'PHPArkitect tier %s is not set. Run via "vendor/bin/qa -t arch", which exports the shipped tier paths.',
                $envVar,
            ));
        }
        if (!\is_file($path)) {
            throw new \RuntimeException(\sprintf('PHPArkitect tier %s points at a missing file: %s', $envVar, $path));
        }

        return require $path;
    };

    // EXTEND the on-by-default baseline. (Drop this line to REPLACE it.)
    $defaultRules = $tier('PHPQACI_ARKITECT_RULES_DEFAULT');

    // OPT IN to extra shipped tiers — uncomment as desired. NOTE: the optional/
    // symfony tiers use ancestry (IsA) rules that need a COMPLETE autoloader —
    // an incomplete one silently matches nothing (false green). See README
    // "Troubleshooting: optional/symfony tiers need a complete autoloader".
    // $optionalRules = $tier('PHPQACI_ARKITECT_RULES_OPTIONAL');
    // $symfonyRules  = $tier('PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY');

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
