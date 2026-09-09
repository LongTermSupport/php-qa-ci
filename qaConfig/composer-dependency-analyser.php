<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * php-qa-ci's own analyser configuration. Every entry records a symbol or
 * package genuinely used through a route a static scan of src/ and tests/
 * cannot follow. Nothing here suppresses a real finding: if an entry stops
 * being true the tool says so, because an ignore that matches nothing is
 * itself reported. Delete a stale entry rather than widening it.
 */

$config = new Configuration();

/*
 * Fixture trees under tests/assets are deliberately outside the autoload map
 * — several exist precisely to be invalid PSR-4 — so they are not code the
 * dependency graph should be read from at all.
 */
$config->addPathToExclude('tests/assets');

/* The stubs those fixtures define are still named from the real test files, which are scanned. */
$config->ignoreUnknownClassesRegex('#^LTS\\\\PHPQA\\\\Tests\\\\Assets\\\\#');

/*
 * PHPStan is `replace`d in composer.json and supplied by vendor-phar/phpstan.phar,
 * and PHPArkitect only ever exists as vendor-phar/phparkitect.phar. Our rule
 * classes and their tests therefore reference classes Composer will never
 * autoload; that is the vendoring strategy working, not a missing dependency.
 */
$config->ignoreUnknownClassesRegex('#^(PHPStan|Arkitect)\\\\#');

/*
 * Composer supplies its own runtime classes to a plugin; composer-plugin-api
 * is a declared requirement but installs nothing to autoload from.
 */
$config->ignoreUnknownClassesRegex('#^Composer\\\\#');

/*
 * RequireExplicitDIAttributeRule names Symfony DI attributes to recognise
 * them in consumer code. php-qa-ci does not depend on Symfony's DI component
 * and must not: the rule reads the attribute names, it never instantiates
 * them.
 */
$config->ignoreUnknownClassesRegex('#^Symfony\\\\Component\\\\DependencyInjection\\\\#');

/*
 * Packages with no PHP symbol for a scan to find. Each is used by the
 * pipeline at run time through a route static analysis cannot follow.
 */
$config
    // Binaries the lanes invoke as subprocesses.
    ->ignoreErrorsOnPackages([
        'shipmonk/composer-dependency-analyser',
        'phpcpd-next/phpcpd',
    ], [ErrorType::UNUSED_DEPENDENCY])
    // PHPStan extensions, loaded by the PHAR through extension-installer, never referenced in our code.
    ->ignoreErrorsOnPackages([
        'phpstan/extension-installer',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-strict-rules',
        'tomasvotruba/type-coverage',
    ], [ErrorType::UNUSED_DEPENDENCY])
    /*
     * Extensions the shipped tools need rather than our own code: openssl for
     * PHIVE signature verification and xml for the PHARs' report writing
     * (tokenizer is used directly, by PhpStanGuardPlugin).
     */
    ->ignoreErrorsOnExtensions(['ext-openssl', 'ext-xml'], [ErrorType::UNUSED_DEPENDENCY]);

return $config;
