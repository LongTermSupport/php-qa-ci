<?php

declare(strict_types=1);

/*
 * A Composer autoload-dev file: runs whenever this repository's autoloader is
 * loaded. PHPUnit's runner defines PHPUNIT_COMPOSER_INSTALL and loads the
 * autoloader before its configuration and bootstrap, so this is the one point
 * that precedes every bootstrap, Infection's mutant bootstrap included. Any
 * other process (bin/qa, Rector) loads the autoloader without it and is left
 * alone. See TempLeakDirectory.
 */

use LTS\PHPQA\Tests\Support\TempLeak\TempLeakDirectory;

if (\defined('PHPUNIT_COMPOSER_INSTALL')) {
    TempLeakDirectory::claim();
}
