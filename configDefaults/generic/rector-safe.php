<?php
declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Config\RectorConfig;

/**
 * The configuration for Rector to run on PHPUnit 10 is also good for PHPUnit 9.1 upwards
 */
return static function (RectorConfig $rectorConfig): void {
    $paths = [
        __DIR__ . '/../../../../thecodingmachine/safe/rector-migrate.php',
        __DIR__ . '/../../../vendor/thecodingmachine/safe/rector-migrate.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $safeFunction = require $path;
            break;
        }
    }
    if (!isset($safeFunction)) {
        throw new \Exception('Could not find safe function');
    }
    $safeFunction($rectorConfig);
    $rectorConfig->cacheClass(MemoryCacheStorage::class);
};