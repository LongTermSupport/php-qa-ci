<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

/*
 * The configuration for Rector to run on PHPUnit 10 is also good for PHPUnit 9.1 upwards
 */

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->sets([
        PHPUnitSetList::PHPUNIT_100,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector::class]);
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
