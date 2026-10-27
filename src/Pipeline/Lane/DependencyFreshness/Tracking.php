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

        $constraints = [];
        foreach (['require', 'require-dev'] as $section) {
            $required = $json[$section] ?? [];
            if (!\is_array($required)) {
                continue;
            }

            foreach ($required as $name => $constraint) {
                $key = strtolower((string)$name);
                if (\is_string($constraint) && !isset($constraints[$key])) {
                    $constraints[$key] = $constraint;
                }
            }
        }

        return $constraints;
    }

    /**
     * @param ?string $constraint    the project's composer.json constraint, null when it does not require the package itself
     * @param ?string $lockedVersion the version composer.lock records, null when it records none
     *
     * @return bool true when the package follows a branch, false when it follows release tags
     */
    public static function followsBranch(?string $constraint, ?string $lockedVersion): bool
    {
        $branch = false;
        $tag    = false;
        $any    = false;
        foreach (null === $constraint ? [] : explode('|', str_replace('||', '|', $constraint)) as $alternative) {
            $ref = self::ref($alternative);
            if (\in_array($ref, self::ANY, true)) {
                $any = true;
            } elseif (self::isBranchVersion($ref)) {
                $branch = true;
            } else {
                $tag = true;
            }
        }

        if (!$any && $branch && !$tag) {
            return true;
        }

        if (!$any && $tag && !$branch) {
            return false;
        }

        return null === $lockedVersion || self::isBranchVersion($lockedVersion);
    }

    /** What one alternative of a constraint follows, without its `#reference`, `@stability` flag or `as` alias. */
    private static function ref(string $alternative): string
    {
        $plain = \Safe\preg_replace(self::NOT_THE_REF, '', $alternative);

        return trim(\is_string($plain) ? $plain : $alternative);
    }
}
