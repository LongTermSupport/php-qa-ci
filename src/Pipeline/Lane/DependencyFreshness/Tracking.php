<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use RuntimeException;
use Safe\Exceptions\JsonException;

/**
 * What a locked package follows: a branch (judged by its head) or release
 * tags (judged by the newest tag the constraint allows). The project's own
 * constraint decides when it names only branches or only tags; when it admits
 * both (`*`, `dev-main || ^1.0`) or the package is not required directly, the
 * locked version decides, because that is what `composer update` keeps
 * following.
 *
 * Composer's own spelling is read: `dev-*` and `*-dev` are branches, a
 * `#reference`, an `@stability` flag and an `as alias` are not part of what a
 * constraint follows, and `*` admits everything.
 *
 * @internal
 */
final readonly class Tracking
{
    /** The parts of an alternative that do not say what it follows: `#abc123`, `@dev`, ` as 1.0.0`. */
    private const string NOT_THE_REF = '/#\S*|@\w+|\s+as\s+\S+/i';

    /** A version starting with this is a branch: `dev-php8.5`. */
    private const string BRANCH_PREFIX = 'dev-';

    /** A version ending with this is a branch: `1.x-dev`. */
    private const string BRANCH_SUFFIX = '-dev';

    /** Composer's spellings of "any version". */
    private const array ANY = ['', '*', 'x', '*.*', 'x.x'];

    /** Whether a version as Composer prints it (`dev-php8.5 93f1ae4`, `1.x-dev`, `1.2.0`) is a branch. */
    public static function isBranchVersion(string $version): bool
    {
        $name = explode(' ', trim($version), 2)[0];

        return str_starts_with($name, self::BRANCH_PREFIX) || str_ends_with($name, self::BRANCH_SUFFIX);
    }

    /**
     * The constraints a project's composer.json puts on the packages it
     * requires, production first (a package in both keeps its production
     * constraint). A composer.json that is missing or unreadable has none:
     * every package is then classified by its locked version.
     *
     * @return array<array-key, string> lower-case package name => constraint
     *
     * @throws RuntimeException when composer.json exists and is not a JSON object
     */
    public static function constraints(string $composerJson): array
    {
        $json        = self::read($composerJson);
        $constraints = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (self::requirements($json, $section) as $name => $constraint) {
                $constraints[$name] ??= $constraint;
            }
        }

        return $constraints;
    }

    /**
     * The packages composer.json requires in `require-dev` only.
     *
     * @return list<array-key> lower-case package names
     *
     * @throws RuntimeException when composer.json exists and is not a JSON object
     */
    public static function devOnlyPackages(string $composerJson): array
    {
        $json = self::read($composerJson);

        return array_values(array_diff(array_keys(self::requirements($json, 'require-dev')), array_keys(self::requirements($json, 'require'))));
    }

    /**
     * @param ?string $constraint    the project's composer.json constraint, null when it does not require the package itself
     * @param ?string $lockedVersion the version composer.lock records, null when it records none
     *
     * @return bool true when the package follows a branch, false when it follows release tags
     */
    public static function followsBranch(?string $constraint, ?string $lockedVersion): bool
    {
        [$branches, $tag, $any] = self::alternatives($constraint);

        if (!$any && [] !== $branches && !$tag) {
            return true;
        }

        if (!$any && $tag && [] === $branches) {
            return false;
        }

        return null === $lockedVersion || self::isBranchVersion($lockedVersion);
    }

    /**
     * The branch alternatives of a constraint that also admits release tags
     * (`dev-php8.5@dev || ^1.0`), as Composer should read them to follow the
     * branch alone. Composer prefers a tag to a branch head for such a
     * constraint, so a package locked on the branch is asked under these
     * alternatives only. Null when the constraint is not that mix.
     */
    public static function branchAlternatives(?string $constraint): ?string
    {
        [$branches, $tag, $any] = self::alternatives($constraint);

        return !$any && $tag && [] !== $branches ? implode(' || ', $branches) : null;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException when composer.json exists and is not a JSON object
     */
    private static function read(string $composerJson): array
    {
        if (!is_file($composerJson)) {
            return [];
        }

        try {
            $json = \Safe\json_decode(\Safe\file_get_contents($composerJson), true);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(\sprintf('%s is not valid JSON: %s', basename($composerJson), $jsonException->getMessage()), 0, $jsonException);
        }

        if (!\is_array($json)) {
            throw new RuntimeException(\sprintf('%s is not a JSON object', basename($composerJson)));
        }

        return $json;
    }

    /**
     * @param array<array-key, mixed> $json
     *
     * @return array<array-key, string> lower-case package name => constraint
     */
    private static function requirements(array $json, string $section): array
    {
        $required = $json[$section] ?? [];
        if (!\is_array($required)) {
            return [];
        }

        $constraints = [];
        foreach ($required as $name => $constraint) {
            if (\is_string($constraint)) {
                $constraints[strtolower((string)$name)] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * @return array{list<string>, bool, bool} the branch alternatives as written, whether a tag alternative is present, whether "any version" is
     */
    private static function alternatives(?string $constraint): array
    {
        $branches = [];
        $tag      = false;
        $any      = false;
        foreach (null === $constraint ? [] : explode('|', str_replace('||', '|', $constraint)) as $alternative) {
            $ref = self::ref($alternative);
            if (\in_array($ref, self::ANY, true)) {
                $any = true;
            } elseif (self::isBranchVersion($ref)) {
                $branches[] = trim($alternative);
            } else {
                $tag = true;
            }
        }

        return [$branches, $tag, $any];
    }

    /** What one alternative of a constraint follows, without its `#reference`, `@stability` flag or `as` alias. */
    private static function ref(string $alternative): string
    {
        $plain = \Safe\preg_replace(self::NOT_THE_REF, '', $alternative);

        return trim(\is_string($plain) ? $plain : $alternative);
    }
}
