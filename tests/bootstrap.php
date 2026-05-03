<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File.
 *
 * This is a placeholder bootstrap file created by PHP-QA-CI.
 *
 * PURPOSE:
 * The bootstrap file is executed before PHPUnit runs any tests.
 * It's used to set up the testing environment, including:
 * - Loading the autoloader
 * - Setting environment variables
 * - Initializing framework components
 * - Configuring test databases
 *
 * SYMFONY PROJECTS:
 * For Symfony projects, you should typically:
 * 1. Load the autoloader
 * 2. Use Dotenv to load .env.test
 *
 * Example for Symfony:
 * ```php
 * use Symfony\Component\Dotenv\Dotenv;
 *
 * require dirname(__DIR__).'/vendor/autoload.php';
 *
 * if (method_exists(Dotenv::class, 'bootEnv')) {
 *     (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
 * }
 * ```
 *
 * REPLACE THIS FILE with your project-specific bootstrap logic.
 */

// Load composer autoloader
require \dirname(__DIR__) . '/vendor/autoload.php';

// Load PHPStan core classes from the bundled phar so that PHPStan\* classes
// (Scope, RuleErrorBuilder, IdentifierRuleError, etc.) are available when
// unit-testing custom PHPStan rules directly — without running the full
// static-analysis pipeline.
$phpstanPhar = \dirname(__DIR__) . '/vendor-phar/phpstan.phar';
if (file_exists($phpstanPhar)) {
    require_once 'phar://' . $phpstanPhar . '/vendor/autoload.php';
}
