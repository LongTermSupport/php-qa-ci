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
 * for a project entirely, set `export useArkitect=0` in qaConfig/qaConfig.inc.bash.
 *
 * The wrapper exports PHPQACI_ARKITECT_SRC_DIR (the pipeline's detected srcDir)
 * and PHPQACI_ARKITECT_RULES_DEFAULT (the resolved default ruleset path).
 */

use Arkitect\ClassSet;
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

    // Generated code is regenerated and cannot be renamed — never check it.
    $config->add(ClassSet::fromDir($srcDir)->excludePath('Generated'), ...$defaultRules);
};
