<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;

/*
 * Consumer fixture: applies php-qa-ci's shipped consumer API-boundary factory.
 * The factory path is provided by the PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY env
 * var (exported by includes/generic/phpArkitect.inc.bash; injected by the test).
 *
 * Library under consumption: Acme\Widget — public @api namespace Acme\Widget\Facade;
 * internal namespace Acme\Widget\Internal (off-limits to consumers).
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
