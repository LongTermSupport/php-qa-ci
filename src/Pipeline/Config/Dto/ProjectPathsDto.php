<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * Every directory the pipeline reads or writes, resolved once at start-up and
 * absolute from then on.
 *
 * Two roots, and confusing them is the usual mistake: `$projectRoot` is the
 * consuming project (php-qa-ci itself when self-testing), `$libraryRoot` is
 * php-qa-ci, which is where `$pharDir` and `$configDefaultsDir` live. Of the
 * rest, `$binDir` is Composer's configured bin directory rather than a fixed
 * vendor/bin, `$projectConfigDir` may not exist, and `$varDir` holds the logs,
 * caches and generated ini files.
 *
 * @api
 */
final readonly class ProjectPathsDto
{
    public function __construct(
        public string $projectRoot,
        public string $libraryRoot,
        public string $binDir,
        public string $srcDir,
        public string $testsDir,
        public string $projectConfigDir,
        public string $varDir,
        public string $cacheDir,
        public string $pharDir,
        public string $configDefaultsDir,
    ) {
    }
}
