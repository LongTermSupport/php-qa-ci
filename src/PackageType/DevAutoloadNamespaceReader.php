<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

use LTS\PHPQA\Helper;

/**
 * Resolves the consuming project's `autoload-dev` PSR-4 namespace prefixes from
 * its `composer.json`, for the API-surface classification rule.
 *
 * Code under an `autoload-dev` namespace (tests, dev/maintainer tooling, QA
 * config) is never installed in a production/runtime install of the package, so
 * it forms no consumer-facing contract and has nothing to classify as `@api` /
 * `@internal`. The classification rule reads these prefixes and skips any
 * class-like whose name starts with one — so a library never has to hand-tag its
 * own test suite or tooling.
 *
 * The optional constructor override makes the reader injectable so unit tests
 * (and the rule's own tests) can drive any mapping without touching a real
 * `composer.json`.
 */
final readonly class DevAutoloadNamespaceReader
{
    /**
     * @param array<int|string, mixed>|null $composerJsonOverride decoded composer.json
     *                                                            for tests; null reads the live project file
     */
    public function __construct(private ?array $composerJsonOverride = null)
    {
    }

    /**
     * @return list<string> fully-qualified namespace prefixes declared under
     *                      `autoload-dev.psr-4` (each as written, trailing `\` included)
     */
    public function namespacePrefixes(): array
    {
        $composerJson = $this->composerJsonOverride ?? Helper::getComposerJsonDecoded();

        $autoloadDev = $composerJson['autoload-dev'] ?? null;
        if (!\is_array($autoloadDev)) {
            return [];
        }

        $psr4 = $autoloadDev['psr-4'] ?? null;
        if (!\is_array($psr4)) {
            return [];
        }

        $prefixes = [];
        foreach (array_keys($psr4) as $prefix) {
            if (\is_string($prefix) && '' !== $prefix) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
