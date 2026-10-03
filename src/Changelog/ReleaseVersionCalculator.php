<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use Safe\Exceptions\JsonException;

/**
 * The next version on a release line. The major is the PHP line written
 * without the dot (`^8.5` is line 85), so it is read from composer.json, never
 * chosen; the minor and patch move on from the newest tag on that line. A
 * line with no tag yet starts at `<major>.0.0`.
 *
 * Tags are compared numerically and matched strictly as `<major>.N.N`, so
 * `85.10.0` is newer than `85.9.0` and `v85.1.0`, `85.1` or `85.1.0-rc1` are
 * not releases on the line.
 *
 * @api
 */
final readonly class ReleaseVersionCalculator
{
    public function lineMajor(string $composerJson): int
    {
        try {
            $composer = \Safe\json_decode($composerJson, true);
        } catch (JsonException $jsonException) {
            throw new ChangelogReleaseException('composer.json is not valid JSON: ' . $jsonException->getMessage(), 0, $jsonException);
        }

        if (!\is_array($composer) || ([] !== $composer && array_is_list($composer))) {
            throw new ChangelogReleaseException('composer.json is not a JSON object');
        }

        $require    = $composer['require'] ?? null;
        $constraint = \is_array($require) ? ($require['php'] ?? null) : null;
        if (!\is_string($constraint)) {
            throw new ChangelogReleaseException('composer.json has no require.php, so it names no release line');
        }

        if (1 !== \Safe\preg_match('/^\^(\d+)\.(\d+)$/', $constraint, $matches) || !isset($matches[1], $matches[2])) {
            throw new ChangelogReleaseException(\sprintf('composer.json require.php must be a single "^X.Y" constraint to name the release line; found "%s"', $constraint));
        }

        return (int)($matches[1] . $matches[2]);
    }

    /** The newest `<major>.N.N` tag, or null when the line has none. */
    public function latestOnLine(int $major, string ...$tags): ?string
    {
        $latest = null;
        foreach ($tags as $tag) {
            if (1 !== \Safe\preg_match('/^' . $major . '\.\d+\.\d+$/', $tag)) {
                continue;
            }

            if (null === $latest || version_compare($tag, $latest, '>')) {
                $latest = $tag;
            }
        }

        return $latest;
    }

    public function next(int $major, ReleaseBumpEnum $bump, string ...$tags): string
    {
        $latest = $this->latestOnLine($major, ...$tags);
        if (null === $latest) {
            return $major . '.0.0';
        }

        [, $minor, $patch] = array_map(intval(...), explode('.', $latest));

        return ReleaseBumpEnum::Minor === $bump
            ? \sprintf('%d.%d.0', $major, $minor + 1)
            : \sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }
}
