<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/*
 * PHP 8.4 specific Rector configuration for php-qa-ci
 * This runs after rector-safe.php and rector-phpunit.php
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
    
    // PHP 8.4 upgrade sets
    // IMPORTANT: SetList::NAMING is EXCLUDED because it includes RenameParamToMatchTypeRector
    // which renames constructor parameters like $productsRow → $productsRowDto
    // This is a BREAKING CHANGE for any code calling constructors with named parameters
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_84,
        SetList::PHP_84,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
        // SetList::NAMING, // EXCLUDED - causes constructor parameter renaming
    ]);
    
    // PHP 8.4 specific rules
    $rectorConfig->rules([
        ExplicitNullableParamTypeRector::class,
    ]);
    
    // Use memory cache for performance
    $rectorConfig->cacheClass(MemoryCacheStorage::class);
    
    // Support for ignoring paths via environment variable
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};