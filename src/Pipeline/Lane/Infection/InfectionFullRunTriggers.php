<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use DOMDocument;

/**
 * The files whose change forces a diff-mode Infection run to run in full:
 * only what Infection and PHPUnit read to decide what is mutated and whether
 * a mutant is killed.
 *
 * - the resolved infection config, and every name Infection accepts for it
 *   at the project override location, since adding or removing an override
 *   changes which config resolves;
 * - the resolved PHPUnit config, and every name Infection's PHPUnit adapter
 *   accepts in its configDir (the project override location);
 * - the test bootstrap the resolved PHPUnit config names in its `bootstrap`
 *   attribute, resolved against the XML's own directory.
 *
 * composer.json, composer.lock and the rest of qaConfig/ are deliberately not
 * here: neither decides what is mutated or whether a mutant is killed, and a
 * full run costs hours. Only paths inside the project are returned, because
 * they are git pathspecs.
 *
 * @internal
 */
final readonly class InfectionFullRunTriggers
{
    /** Every infection config name Infection itself accepts. */
    public const array INFECTION_CONFIG_NAMES = ['infection.json', 'infection.json5', 'infection.json.dist', 'infection.json5.dist'];

    /** Every PHPUnit config name Infection's PHPUnit adapter accepts in its configDir. */
    public const array PHPUNIT_CONFIG_NAMES = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    /** @return list<string> absolute paths, inside the project */
    public function paths(string $projectRoot, string $projectConfigDir, string $infectionConfig, string $phpunitConfig): array
    {
        $candidates = [
            $infectionConfig,
            ...array_map(static fn (string $name): string => $projectConfigDir . '/' . $name, self::INFECTION_CONFIG_NAMES),
            $phpunitConfig,
            ...array_map(static fn (string $name): string => $projectConfigDir . '/' . $name, self::PHPUNIT_CONFIG_NAMES),
            $this->bootstrap($phpunitConfig),
        ];

        $root  = rtrim($projectRoot, '/') . '/';
        $paths = [];
        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }

            $path = $this->normalise($candidate);
            if (str_starts_with($path, $root) && !\in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The bootstrap the PHPUnit config names, absolute; null when it names
     * none or does not parse (the config itself is a trigger either way, and
     * PHPUnit fails on an unparseable one before any mutant runs).
     */
    private function bootstrap(string $phpunitConfig): ?string
    {
        if (!is_file($phpunitConfig)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = $document->load($phpunitConfig, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $bootstrap = $loaded ? $document->documentElement?->getAttribute('bootstrap') : null;
        if (null === $bootstrap || '' === $bootstrap) {
            return null;
        }

        return str_starts_with($bootstrap, '/') ? $bootstrap : \dirname($phpunitConfig) . '/' . $bootstrap;
    }

    /** `.` and `..` segments resolved lexically: the file need not exist, and a symlinked vendor/ must not move it. */
    private function normalise(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
