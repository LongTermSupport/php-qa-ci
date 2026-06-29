<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;

return static function (Config $config): void {
    // OPT IN to the stricter generic tier, as a real project would.
    $optionalRulesFile = getenv('PHPQACI_ARKITECT_RULES_OPTIONAL')
        ?: __DIR__ . '/../../../../configDefaults/generic/phparkitect-rules-optional.php';
    $optionalRules = require $optionalRulesFile;

    $config->add(ClassSet::fromDir(__DIR__ . '/src'), ...$optionalRules);
};
