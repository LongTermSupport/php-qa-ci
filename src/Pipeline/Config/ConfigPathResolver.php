<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * Three-level config file lookup, first hit wins:
 *
 *  1. the project's own override under qaConfig/
 *  2. the platform default under configDefaults/<platform>/
 *  3. the generic default under configDefaults/generic/
 *
 * The generic path is returned even when it does not exist, so a caller can
 * report the path it looked for.
 *
 * @api
 */
final readonly class ConfigPathResolver
{
    public function __construct(
        private string $projectConfigDir,
        private string $configDefaultsDir,
        private PlatformEnum $platform,
    ) {
    }

    public function resolve(string $relativePath): string
    {
        $candidates = [
            $this->projectConfigDir . '/' . $relativePath,
            $this->configDefaultsDir . '/' . $this->platform->value . '/' . $relativePath,
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return $this->configDefaultsDir . '/' . PlatformEnum::Generic->value . '/' . $relativePath;
    }

    /** Whether the project supplies its own copy of this config file. */
    public function isProjectOverride(string $relativePath): bool
    {
        return file_exists($this->projectConfigDir . '/' . $relativePath);
    }
}
