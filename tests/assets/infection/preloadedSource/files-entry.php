<?php

declare(strict_types=1);

/*
 * An autoload-dev "files" entry of the shape #155 found: run only under
 * PHPUnit, it loads a class from the source tree Infection mutates, before
 * any bootstrap.
 */

if (\defined('PHPUNIT_COMPOSER_INSTALL')) {
    require_once __DIR__ . '/src/Preloaded.php';
}
