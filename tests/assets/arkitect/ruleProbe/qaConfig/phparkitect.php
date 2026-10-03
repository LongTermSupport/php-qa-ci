<?php

declare(strict_types=1);

/*
 * A project entry config in the shape of templates/qaConfig-phparkitect.php:
 * its class set is hard-coded to the project's src/, it extends the shipped
 * default tier through the environment, and it adds one bespoke rule. Every
 * rule here has zero instances in src/, which is the case bin/arkitect-rule
 * exists for: the probe swaps the class set for a fixture path and the rules
 * stay exactly these.
 */

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    $defaultRulesFile = getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
    if (false === $defaultRulesFile || '' === $defaultRulesFile) {
        throw new RuntimeException('PHPQACI_ARKITECT_RULES_DEFAULT is not set');
    }

    $config->add(
        ClassSet::fromDir(__DIR__ . '/../src'),
        ...require $defaultRulesFile,
        ...[
            Rule::allClasses()
                ->that(new ResideInOneOfTheseNamespaces('ArkitectProbe\\Controller'))
                ->should(new HaveNameMatching('*Controller'))
                ->because('a Controller suffix tells routing code apart from the services it calls'),
        ],
    );
};
