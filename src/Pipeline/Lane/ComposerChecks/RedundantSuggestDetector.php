<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ComposerChecks;

/**
 * A `suggest` entry naming a package the project already requires.
 *
 * `suggest` is advice a consumer acts on, so advice to install something they
 * already get is noise, and noise in that block trains the reader to skip all
 * of it. Decidable from the manifest alone, which is why it is checked here;
 * whether a suggested package is abandoned is not, since that needs the
 * registry.
 *
 * @internal
 */
final readonly class RedundantSuggestDetector
{
    private const string REQUIRE = 'require';

    private const string REQUIRE_DEV = 'require-dev';

    /**
     * @param array<int|string, mixed> $composerJson
     *
     * @return list<string> `<package> (<section>)` per redundant entry, in the order suggest declares them
     */
    public function check(array $composerJson): array
    {
        $suggest = $composerJson['suggest'] ?? null;
        if (!\is_array($suggest)) {
            return [];
        }

        $require    = $this->section($composerJson, self::REQUIRE);
        $requireDev = $this->section($composerJson, self::REQUIRE_DEV);

        $found = [];
        foreach (array_keys($suggest) as $package) {
            $name = (string)$package;
            if (\in_array($name, $require, true)) {
                $found[] = $name . ' (' . self::REQUIRE . ')';

                continue;
            }

            if (\in_array($name, $requireDev, true)) {
                $found[] = $name . ' (' . self::REQUIRE_DEV . ')';
            }
        }

        return $found;
    }

    /**
     * @param array<int|string, mixed> $composerJson
     *
     * @return list<string>
     */
    private function section(array $composerJson, string $name): array
    {
        $section = $composerJson[$name] ?? null;

        return \is_array($section) ? array_map(strval(...), array_keys($section)) : [];
    }
}
