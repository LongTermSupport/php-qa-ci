<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use RuntimeException;
use Safe\Exceptions\JsonException;

/**
 * The package names a composer.lock holds, production then dev. A lock that
 * is not a JSON object is refused rather than read as holding nothing, which
 * would pass the freshness check without asking Composer anything.
 *
 * @internal
 */
final readonly class ComposerLock
{
    private const string PACKAGES = 'packages';

    private const string PACKAGES_DEV = 'packages-dev';

    /** @return list<string> */
    public static function packageNames(string $path): array
    {
        try {
            $lock = \Safe\json_decode(\Safe\file_get_contents($path), true);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(\sprintf('%s is not valid JSON: %s', basename($path), $jsonException->getMessage()), 0, $jsonException);
        }

        if (!\is_array($lock)) {
            throw new RuntimeException(\sprintf('%s is not a JSON object', basename($path)));
        }

        $names = [];
        foreach ([self::PACKAGES, self::PACKAGES_DEV] as $section) {
            $packages = $lock[$section] ?? [];
            if (!\is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                $name = \is_array($package) ? $package['name'] ?? null : null;
                if (\is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
