<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;

/*
 * Consumer fixture that VIOLATES the boundary: its code reaches into the
 * library's internal namespace. Same factory + library coordinates as the valid
 * fixture; only the consumer source differs.
 */
return static function (Config $config): void {
    $factoryPath = getenv('PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY');
    if (false === $factoryPath || !is_file($factoryPath)) {
        throw new RuntimeException('PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY is not set to a readable factory file');
    }

    $consumerMustOnlyDependOn = require $factoryPath;

    $rules = $consumerMustOnlyDependOn('Acme\\Widget\\Facade', 'Acme\\Widget\\Internal');

    $config->add(ClassSet::fromDir(__DIR__ . '/src'), ...$rules);
};
