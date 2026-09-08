<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * Every directory the pipeline reads or writes, resolved once at start-up.
 *
 * @internal
 */
final readonly class ProjectPathsDto
{
    public function __construct(
        /** The consuming project (or php-qa-ci itself when self-testing). */
        public string $projectRoot,
        /** The php-qa-ci library root (where bin/, configDefaults/, vendor-phar/ live). */
        public string $libraryRoot,
        /** The project's Composer bin directory (usually vendor/bin). */
        public string $binDir,
        public string $srcDir,
        public string $testsDir,
        /** The project's qaConfig/ directory (may not exist). */
        public string $projectConfigDir,
        /** var/qa under the project root: logs, caches, generated ini files. */
        public string $varDir,
        public string $cacheDir,
        /** The library's vendor-phar/ directory. */
        public string $pharDir,
        /** The library's configDefaults/ directory. */
        public string $configDefaultsDir,
    ) {
    }
}
