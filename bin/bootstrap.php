<?php

declare(strict_types=1);

/**
 * Shared Composer autoload discovery for bin/ PHP entrypoints (php-qa-ci).
 *
 * Looks for an autoloader either three levels up (php-qa-ci installed as a
 * Composer dependency under vendor/lts/php-qa-ci/bin/) or under this
 * package's own vendor/ (php-qa-ci itself is the root project). Requires
 * whichever is found and returns control to the entrypoint, or prints
 * guidance and exits 1 if neither is found.
 *
 * Required as: require __DIR__.'/bootstrap.php';
 *
 * After a successful require, $phpQaCiBootstrapAutoloadPath holds the absolute
 * path of the autoloader that was loaded (bin/qa-php derives the project root
 * from it).
 *
 * An entrypoint whose failure message must differ from the default (see
 * bin/managed-source) sets $phpQaCiBootstrapFailureMessage before requiring
 * this file; it is used verbatim instead of the default guidance.
 */

$phpQaCiBootstrapFiles = [
    __DIR__.'/../../../autoload.php',
    __DIR__.'/../vendor/autoload.php',
];

$phpQaCiBootstrapAutoloadFound = false;
foreach ($phpQaCiBootstrapFiles as $phpQaCiBootstrapFile) {
    if (file_exists($phpQaCiBootstrapFile)) {
        require $phpQaCiBootstrapFile;
        $phpQaCiBootstrapAutoloadFound = true;
        $phpQaCiBootstrapAutoloadPath  = realpath($phpQaCiBootstrapFile);
        break;
    }
}

if (!$phpQaCiBootstrapAutoloadFound) {
    echo $phpQaCiBootstrapFailureMessage ?? (
        'You need to set up the project dependencies using the following commands:'.PHP_EOL.
        'curl -s http://getcomposer.org/installer | php'.PHP_EOL.
        'php composer.phar install'.PHP_EOL
    );
    die(1);
}
