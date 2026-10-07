<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — DEFAULT ENTRY CONFIG (on by default).
 *
 * configPath resolves this file whenever a project has NOT supplied its own
 * qaConfig/phparkitect.php, so every consuming project gets the generic-safe
 * default baseline (phparkitect-rules-default.php) applied to its detected
 * source directory — no per-project setup required. This is the arkitect
 * equivalent of the rules-default PHPStan baseline.
 *
 * A project takes over by adding qaConfig/phparkitect.php (use the template at
 * templates/qaConfig-phparkitect.php), where it can extend the defaults, opt
 * into the optional/symfony tiers, and add bespoke rules. To turn arkitect off
 * for a project entirely, call `->withArkitect(false)` in qaConfig/qa.php.
 *
 * The pipeline exports PHPQACI_ARKITECT_SRC_DIR (the detected srcDir),
 * PHPQACI_ARKITECT_RULES_DEFAULT (the resolved default ruleset path) and
 * PHPQACI_ARKITECT_CLASS_SET (the class-set factory, phparkitect-class-set.php,
 * which reads PHPQACI_ARKITECT_EXCLUDE_PATHS and PHPQACI_ARKITECT_IGNORED_PATHS).
 */

use Arkitect\CLI\Config;

return static function (Config $config): void {
    $srcDir = getenv('PHPQACI_ARKITECT_SRC_DIR') ?: (getcwd() . '/src');

    // Nothing to analyse (e.g. a project without a conventional src/) — pass cleanly.
    if (!\is_dir($srcDir)) {
        return;
    }

    $defaultRulesFile = getenv('PHPQACI_ARKITECT_RULES_DEFAULT')
        ?: __DIR__ . '/phparkitect-rules-default.php';
    $defaultRules = \is_file($defaultRulesFile) ? require $defaultRulesFile : [];

    if ([] === $defaultRules) {
        fwrite(STDERR, "PHPArkitect: default ruleset resolved empty (looked at {$defaultRulesFile}) — NO rules applied.\n");

        return;
    }

    // The class set leaves out 'Generated', each ->withArkitectExcludedPaths()
    // glob and each ->withIgnoredPaths() path under src/; the factory says how.
    $classSetFile = getenv('PHPQACI_ARKITECT_CLASS_SET') ?: __DIR__ . '/phparkitect-class-set.php';

    $config->add((require $classSetFile)($srcDir), ...$defaultRules);
};
