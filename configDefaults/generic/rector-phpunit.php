<?php
declare(strict_types=1);

use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Config\RectorConfig;

/**
 * The configuration for Rector to run on PHPUnit 10 is also good for PHPUnit 9.1 upwards
 */
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->sets([
                            PHPUnitSetList::PHPUNIT_100,
                            PHPUnitSetList::PHPUNIT_CODE_QUALITY,
                            PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
                        ]);
};