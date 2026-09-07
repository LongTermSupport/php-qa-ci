<?php

declare(strict_types=1);

namespace LTS\PHPQA\InfectionConfig;

/**
 * Pure decision: infection.json's `source.directories` entries must resolve,
 * relative to the directory containing that file, to a real directory on
 * disk — Infection itself resolves them relative to infection.json's own
 * location, never the project root or the process CWD.
 *
 * @internal
 */
final readonly class InfectionConfigSourceDirectoriesDetector
{
    /**
     * @param array<string, mixed> $infectionJson decoded infection.json
     * @param string               $configFileDir the directory containing that
     *                                            infection.json file
     *
     * @return list<string> one message per declared directory that does not
     *                      resolve to an existing directory; empty when every
     *                      declared directory resolves
     */
    public function check(array $infectionJson, string $configFileDir): array
    {
        $source = $infectionJson['source'] ?? null;
        if (!\is_array($source)) {
            return [];
        }

        $directories = $source['directories'] ?? null;
        if (!\is_array($directories)) {
            return [];
        }

        $problems = [];
        foreach ($directories as $directory) {
            if (!\is_string($directory)) {
                continue;
            }

            if ('' === $directory) {
                continue;
            }

            $resolved = $this->resolve($configFileDir, $directory);
            if (!is_dir($resolved)) {
                $problems[] = \sprintf(
                    'source.directories entry "%s" does not resolve to an existing directory '
                    . '(resolved, relative to the config file\'s own directory, to "%s").',
                    $directory,
                    $resolved,
                );
            }
        }

        return $problems;
    }

    private function resolve(string $configFileDir, string $directory): string
    {
        if (str_starts_with($directory, '/')) {
            return $directory;
        }

        return $configFileDir . '/' . $directory;
    }
}
