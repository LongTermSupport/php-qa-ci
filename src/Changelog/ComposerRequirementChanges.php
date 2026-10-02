<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use Safe\Exceptions\JsonException;

/**
 * The runtime requirements a range added or re-constrained: a key new to
 * composer.json's `require`, or a constraint that changed. Either can break a
 * consumer that cannot meet it. A removed requirement and anything in
 * `require-dev` cannot, so they are not reported; nor is anything when
 * composer.json is missing on one side, since there is no earlier contract
 * to have broken.
 *
 * @api
 */
final readonly class ComposerRequirementChanges
{
    /** @return list<string> one description per change, e.g. `ext-pcntl * added` */
    public function between(?string $baseComposerJson, ?string $headComposerJson): array
    {
        if (null === $baseComposerJson || null === $headComposerJson) {
            return [];
        }

        try {
            $base = $this->requirements($baseComposerJson);
            $head = $this->requirements($headComposerJson);
        } catch (JsonException $jsonException) {
            return ['composer.json does not parse on one side of the range, so its requirements cannot be compared (' . $jsonException->getMessage() . ')'];
        }

        $changes = [];
        foreach ($head as $package => $constraint) {
            $before = $base[$package] ?? null;
            if (null === $before) {
                $changes[] = $package . ' ' . $constraint . ' added';
            } elseif ($before !== $constraint) {
                $changes[] = $package . ' ' . $before . ' → ' . $constraint;
            }
        }

        return $changes;
    }

    /** @return array<string, string> package => constraint */
    private function requirements(string $composerJson): array
    {
        $composer = \Safe\json_decode($composerJson, true);
        $require  = \is_array($composer) ? ($composer['require'] ?? null) : null;
        if (!\is_array($require)) {
            return [];
        }

        $requirements = [];
        foreach ($require as $package => $constraint) {
            if (\is_string($constraint)) {
                $requirements[(string)$package] = $constraint;
            }
        }

        return $requirements;
    }
}
