<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner\Exception;

use RuntimeException;

/**
 * @internal
 */
final class MissingPharException extends RuntimeException
{
    public static function noPhiveXml(string $path): self
    {
        return new self('phive.xml is required but was not found at ' . $path);
    }

    public static function missing(string $pharDir, string ...$names): self
    {
        return new self(\sprintf(
            'Missing PHAR tool(s) under %s: %s. Reinstall php-qa-ci (composer reinstall lts/php-qa-ci); a maintainer rebuilds self-built PHARs with scripts/build-phar.bash --all.',
            $pharDir,
            implode(', ', $names),
        ));
    }

    public static function turboManifestMissing(string $path): self
    {
        return new self(\sprintf(
            '%s is missing; it pins the PHPStan Turbo binaries for the shipped phpstan.phar. Reinstall php-qa-ci (composer reinstall lts/php-qa-ci).',
            $path,
        ));
    }

    public static function turboManifestMismatch(string $path, string $manifestVersion, string $pharVersion): self
    {
        return new self(\sprintf(
            '%s is for PHPStan %s but the shipped phpstan.phar is %s, so PHPStan would run without Turbo. Reinstall php-qa-ci; a maintainer runs scripts/tool-install.bash update.',
            $path,
            $manifestVersion,
            $pharVersion,
        ));
    }
}
