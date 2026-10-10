<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use JsonException;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use stdClass;

/**
 * The infection.json the infection lane hands Infection: a copy of the
 * resolved config under var/qa/, with the withIgnoredPaths() entries under its
 * source directories excluded (derive()) or with nothing excluded (relocated()).
 *
 * Infection takes no exclusion on its command line, so the lane writes a
 * derived copy of the resolved config under var/qa/. Every setting Infection
 * resolves against the config file's own directory (PATH_KEYS) is made
 * absolute, so the copy means the same from its new place, and an absent
 * configDir that Infection would default to that directory is stated
 * (DEFAULTED_CONFIG_DIRS); `bootstrap` is resolved against the working
 * directory and a php:// log target is a stream, so both are left as
 * written. An ignored
 * source directory is dropped from source.directories, and an ignored path
 * inside one is added to source.excludes as a regex anchored at that
 * directory: Infection matches excludes against the path relative to each
 * source directory, so `#^Legacy(?:/|$)#` drops src/Legacy and keeps
 * src/Domain/Legacy, where a plain `Legacy` would drop both.
 *
 * @internal
 */
final readonly class IgnoredPathsInfectionConfig
{
    /** The settings Infection resolves against the config file's directory, dotted. */
    public const array PATH_KEYS = [
        'source.directories',
        'logs.text',
        'logs.summary',
        'logs.json',
        'logs.html',
        'logs.debug',
        'logs.perMutator',
        'logs.gitlab',
        'logs.summaryJson',
        'tmpDir',
        'phpUnit.configDir',
        'phpUnit.customPath',
        'phpStan.configDir',
        'phpStan.customPath',
        'mago.configDir',
        'mago.customPath',
        'debug.logFile',
    ];

    /** The sections whose absent configDir Infection defaults to the config file's directory. */
    public const array DEFAULTED_CONFIG_DIRS = ['phpUnit', 'phpStan', 'mago'];

    /** The prefix of a log target Infection writes to as a stream (php://stdout, php://stderr, php://output). */
    public const string PHP_STREAM = 'php://';

    /**
     * @return array<array-key, mixed>|null the derived config, ready for json_encode()
     *                                      (an empty object in the original is a
     *                                      stdClass), or null when no ignored path is
     *                                      under a source directory, so the config is
     *                                      used as it is
     *
     * @throws JsonException when the config is not a JSON object
     */
    public function derive(string $configPath, IgnoredPaths $ignored): ?array
    {
        if ($ignored->isEmpty()) {
            return null;
        }

        $config      = $this->read($configPath);
        $configDir   = \dirname($configPath);
        $source      = \is_array($config['source'] ?? null) ? $config['source'] : [];
        $directories = array_map(fn (string $directory): string => $this->absolute($configDir, $directory), $this->strings($source['directories'] ?? null));

        $kept     = [];
        $excludes = [];
        foreach ($directories as $directory) {
            if ($ignored->contains($directory)) {
                continue;
            }

            $kept[] = $directory;
            foreach ($ignored->absolute as $path) {
                if (str_starts_with($path, $directory . '/')) {
                    $excludes[] = '#^' . preg_quote(substr($path, \strlen($directory) + 1), '#') . '(?:/|$)#';
                }
            }
        }

        if ($kept === $directories && [] === $excludes) {
            return null;
        }

        $config                = $this->relocate($config, $configDir);
        $source                = \is_array($config['source'] ?? null) ? $config['source'] : [];
        $source['directories'] = $kept;
        if ([] !== $excludes) {
            $source['excludes'] = [...$this->strings($source['excludes'] ?? null), ...$excludes];
        }

        $config['source'] = $source;

        return $this->restored($config, $configPath);
    }

    /**
     * The config at $configPath as a copy elsewhere must state it to mean the
     * same: every PATH_KEYS setting absolute and every DEFAULTED_CONFIG_DIRS
     * configDir stated, nothing excluded. The lane always runs Infection from a
     * copy, because it adds the JSON log it reads.
     *
     * @return array<array-key, mixed> ready for json_encode(), an empty object in the original still one
     *
     * @throws JsonException when the config is not a JSON object
     */
    public function relocated(string $configPath): array
    {
        return $this->restored($this->relocate($this->read($configPath), \dirname($configPath)), $configPath);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private function read(string $configPath): array
    {
        $config = \Safe\json_decode(\Safe\file_get_contents($configPath), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($config)) {
            throw new JsonException($configPath . ' does not hold a JSON object');
        }

        return $config;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function relocate(array $config, string $configDir): array
    {
        foreach (self::PATH_KEYS as $key) {
            $config = $this->absolutise($config, $configDir, ...explode('.', $key));
        }

        foreach (self::DEFAULTED_CONFIG_DIRS as $section) {
            $settings = \is_array($config[$section] ?? null) ? $config[$section] : [];
            if (!\array_key_exists('configDir', $settings)) {
                $config[$section] = ['configDir' => $configDir, ...$settings];
            }
        }

        return $config;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private function restored(array $config, string $configPath): array
    {
        $restored = $this->restoreEmptyObjects($config, \Safe\json_decode(\Safe\file_get_contents($configPath), false, flags: \JSON_THROW_ON_ERROR));
        if (!\is_array($restored)) {
            throw new JsonException($configPath . ' does not hold a JSON object');
        }

        return $restored;
    }

    /**
     * $value with every `[]` that $shape, the config decoded to objects, holds
     * as an empty object turned back into one: decoding to arrays loses the
     * difference, and Infection's schema rejects `[]` where it wants `{}`.
     */
    private function restoreEmptyObjects(mixed $value, mixed $shape): mixed
    {
        if ($shape instanceof stdClass && [] === $value) {
            return new stdClass();
        }

        if (!\is_array($value) || (!$shape instanceof stdClass && !\is_array($shape))) {
            return $value;
        }

        $shapeEntries = $shape instanceof stdClass ? get_object_vars($shape) : $shape;
        foreach ($value as $key => $entry) {
            if (\array_key_exists($key, $shapeEntries)) {
                $value[$key] = $this->restoreEmptyObjects($entry, $shapeEntries[$key]);
            }
        }

        return $value;
    }

    /**
     * $config with the setting at $path made absolute, when it is present.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function absolutise(array $config, string $configDir, string $key, string ...$rest): array
    {
        if (!\array_key_exists($key, $config)) {
            return $config;
        }

        $value = $config[$key];
        if ([] !== $rest) {
            if (\is_array($value)) {
                $config[$key] = $this->absolutise($value, $configDir, ...$rest);
            }

            return $config;
        }

        if (\is_string($value)) {
            $config[$key] = $this->absolute($configDir, $value);
        } elseif (\is_array($value)) {
            $config[$key] = array_map(fn (string $path): string => $this->absolute($configDir, $path), $this->strings($value));
        }

        return $config;
    }

    /**
     * $path resolved against $base when it is relative, with `.` and `..`
     * segments folded; a php:// stream, which Infection writes to rather than
     * resolving, as written.
     */
    private function absolute(string $base, string $path): string
    {
        if (str_starts_with($path, self::PHP_STREAM)) {
            return $path;
        }

        $segments = [];
        foreach (explode('/', str_starts_with($path, '/') ? $path : $base . '/' . $path) as $segment) {
            if ('..' === $segment) {
                array_pop($segments);
            } elseif ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return '/' . implode('/', $segments);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
