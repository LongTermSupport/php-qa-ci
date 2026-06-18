<?php

declare(strict_types=1);

/**
 * PHPArkitect architecture rules — STARTING POINT (opt-in).
 *
 * PHPArkitect enforces *structural* rules that PHPStan expresses awkwardly:
 * class-naming conventions, namespace layering, and dependency direction.
 *
 * HOW TO ENABLE for your project:
 *   1. Copy this template into your project's qaConfig/ directory:
 *        cp vendor/lts/php-qa-ci/templates/qaConfig-phparkitect.php \
 *           qaConfig/phparkitect.php
 *   2. Edit the rules below to match your namespaces.
 *   3. Run it:  vendor/bin/qa -t arch
 *
 * Until qaConfig/phparkitect.php exists the tool skips cleanly, so adding the
 * file is the only thing needed to switch architecture checks on.
 *
 * Paths are defined HERE (not on the command line): ClassSet::fromDir(...).
 * __DIR__ is the qaConfig/ directory, so '/../src' points at the project src/.
 * The pipeline passes --autoload=vendor/autoload.php for you.
 *
 * Full rule catalogue: https://github.com/phparkitect/arkitect
 */

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    // Adjust to your project's root namespace and source directory.
    $rootNamespace = 'App';
    $classSet      = ClassSet::fromDir(__DIR__ . '/../src');

    $rules = [];

    // Example 1 — naming convention.
    // Every class in App\Controller must be suffixed *Controller.
    // (A rule matches zero classes harmlessly if the namespace is absent.)
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces($rootNamespace . '\\Controller'))
        ->should(new HaveNameMatching('*Controller'))
        ->because('controllers must be named consistently');

    // Example 2 — dependency direction (layering).
    // The domain layer must not depend on the infrastructure layer.
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces($rootNamespace . '\\Domain'))
        ->should(new NotDependsOnTheseNamespaces($rootNamespace . '\\Infrastructure'))
        ->because('the domain layer must stay free of infrastructure concerns');

    $config->add($classSet, ...$rules);
};
