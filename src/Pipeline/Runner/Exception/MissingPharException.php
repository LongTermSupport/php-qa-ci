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
}
