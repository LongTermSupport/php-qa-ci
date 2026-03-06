<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\Config\RectorConfig;

/*
 * The configuration for Rector to run on PHPUnit 10 is also good for PHPUnit 9.1 upwards
 */
return static function (RectorConfig $rectorConfig): void {
    // Limit parallel processing to use only half of available CPU threads
    // to avoid overwhelming the system
    $cpuThreads = (int) shell_exec('nproc') ?: 4;  // Default to 4 if nproc fails
    $maxProcesses = max(1, (int) floor($cpuThreads / 2));  // Use half the threads, minimum 1

    // Parameters: timeout (seconds), max processes, job size (files per job)
    $rectorConfig->parallel(
        120,  // Default timeout
        $maxProcesses,  // Use only half of available CPU threads
        16    // Default job size
    );

    // Preflight check: Ensure thecodingmachine/safe is in production dependencies
    // Read composer.json directly — cannot use Helper class here because Rector
    // loads config before --autoload-file is processed
    $composerJsonPath = ($_SERVER['PWD'] ?? getcwd()) . '/composer.json';
    if (!file_exists($composerJsonPath)) {
        throw new \RuntimeException('Cannot find composer.json at ' . $composerJsonPath);
    }
    $composerData = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
    $require = $composerData['require'] ?? [];
    $requireDev = $composerData['require-dev'] ?? [];
    
    $safePackages = ['thecodingmachine/safe', 'shish/safe'];
    $hasProductionSafe = false;
    $hasDevSafe = false;
    
    foreach ($safePackages as $package) {
        if (isset($require[$package])) {
            $hasProductionSafe = true;
            break;
        }
        if (isset($requireDev[$package])) {
            $hasDevSafe = true;
        }
    }
    
    if (!$hasProductionSafe) {
        $errorMessage = "Safe function conversion requires thecodingmachine/safe to be declared as a production dependency.\n\n";
        
        if ($hasDevSafe) {
            $errorMessage .= "Found thecodingmachine/safe in require-dev, but Safe functions create a runtime dependency.\n";
            $errorMessage .= "Move it to production dependencies with:\n";
            $errorMessage .= "  composer remove --dev thecodingmachine/safe\n";
            $errorMessage .= "  composer require thecodingmachine/safe\n\n";
        } else {
            $errorMessage .= "Install thecodingmachine/safe as a production dependency with:\n";
            $errorMessage .= "  composer require thecodingmachine/safe\n\n";
        }
        
        $errorMessage .= "Why this is required:\n";
        $errorMessage .= "- Safe functions (e.g., Safe\\json_decode) throw exceptions instead of returning false\n";
        $errorMessage .= "- This creates a hard runtime dependency on thecodingmachine/safe\n";
        $errorMessage .= "- The package must be available in production, not just during development\n";
        $errorMessage .= "- Rector Safe conversion will modify your production code to use Safe functions\n";
        
        throw new \RuntimeException($errorMessage);
    }
    $paths = [
        // When php-qa-ci is installed as a dependency: vendor/lts/php-qa-ci/configDefaults/generic/ -> up 4 -> vendor/
        __DIR__ . '/../../../../thecodingmachine/safe/rector-migrate.php',
        __DIR__ . '/../../../../shish/safe/rector-migrate.php',
        // When php-qa-ci IS the root project: configDefaults/generic/ -> up 2 -> vendor/
        __DIR__ . '/../../vendor/thecodingmachine/safe/rector-migrate.php',
        __DIR__ . '/../../vendor/shish/safe/rector-migrate.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $safeFunction = require $path;
            break;
        }
    }
    if (!isset($safeFunction)) {
        throw new Exception('Could not find safe function');
    }
    $safeFunction($rectorConfig);
    $rectorConfig->cacheClass(MemoryCacheStorage::class);
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
