<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;

return static function (Config $config): void {
    // EXTEND the shipped php-qa-ci default rules, exactly as a real project would:
    // the wrapper exports PHPQACI_ARKITECT_RULES_DEFAULT; fall back to the
    // relative path so this fixture also runs when invoked directly.
    $defaultRulesFile = getenv('PHPQACI_ARKITECT_RULES_DEFAULT')
        ?: __DIR__ . '/../../../../configDefaults/generic/phparkitect-rules-default.php';
    $defaultRules = require $defaultRulesFile;

    $config->add(ClassSet::fromDir(__DIR__ . '/src'), ...$defaultRules);
};
