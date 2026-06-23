<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

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

    $rectorConfig->sets([
        PHPUnitSetList::PHPUNIT_100,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector::class]);
    $rectorConfig->skip([Rector\PHPUnit\PHPUnit100\Rector\Class_\ParentTestClassConstructorRector::class]);
    // ScalarArgumentToExpectedParamTypeRector incorrectly converts test values that have semantic meaning
    // e.g., it changes name: '' to name: 0 when the parameter accepts int|string
    // but in tests, '' and 0 have very different meanings and test different code paths
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\MethodCall\ScalarArgumentToExpectedParamTypeRector::class]);
    // StringCastAssertStringContainsStringRector adds a (string) cast to the haystack of
    // assertStringContainsString(). Rector cannot see PHPStan's flow narrowing, so for the idiomatic
    // `assertNotNull($x); assertStringContainsString(..., $x);` pattern (where $x is ?string proven
    // non-null) it casts a value PHPStan already knows is a string — which php-qa-ci's PHPStan-max then
    // rejects as "casting to string something that's already string". The two tools shipped here would
    // contradict each other on correct code with no fix that satisfies both. PHPStan is the stronger
    // guarantee, so we keep it and drop the redundant-cast-adding rule.
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\MethodCall\StringCastAssertStringContainsStringRector::class]);
    $rectorConfig->rules([
        Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitSelfCallRector::class,
        Rector\PHPUnit\CodeQuality\Rector\Class_\YieldDataProviderRector::class,
    ]);
    $rectorConfig->cacheClass(MemoryCacheStorage::class);
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
