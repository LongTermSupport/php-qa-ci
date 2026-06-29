<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — OPTIONAL Symfony ruleset (the
 * "rules-optional-symfony" tier).
 *
 * Symfony-specific naming conventions, OPT-IN (mirrors
 * rules-optional-symfony.neon for PHPStan). A Symfony project composes it into
 * its qaConfig/phparkitect.php:
 *
 *   $default = require getenv('PHPQACI_ARKITECT_RULES_DEFAULT');
 *   $symfony = require getenv('PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY');
 *   $config->add($classSet, ...$default, ...$symfony, ...$projectRules);
 *
 * Rules key off framework base types (resolved via the project's --autoload),
 * so they stay namespace-agnostic and work in any Symfony project.
 *
 * @return list<\Arkitect\Rules\ArchRule>
 */

use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\IsA;
use Arkitect\Rules\Rule;

return [
    // Console commands are suffixed *Command.
    Rule::allClasses()
        ->that(new IsA('Symfony\\Component\\Console\\Command\\Command'))
        ->should(new HaveNameMatching('*Command'))
        ->because('a Command suffix makes console commands obvious and consistent'),

    // Event subscribers are suffixed *Subscriber.
    Rule::allClasses()
        ->that(new IsA('Symfony\\Component\\EventDispatcher\\EventSubscriberInterface'))
        ->should(new HaveNameMatching('*Subscriber'))
        ->because('a Subscriber suffix makes event subscribers obvious and consistent'),
];
