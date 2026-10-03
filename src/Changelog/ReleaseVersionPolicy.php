<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use InvalidArgumentException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use Safe\Exceptions\JsonException;

/**
 * How a project numbers its releases, declared in qaConfig/qa.php with
 * `withReleaseVersionPolicy()` and read by bin/changelog-release and the
 * changelog lane.
 *
 * - semanticVersioning(), the default: `### Changed — breaking` or
 *   `### Removed` moves the major (the minor while the major is 0), any
 *   other feature or change the minor, fixes alone the patch. A project with
 *   no release tag yet releases $firstVersion, `0.1.0` unless given.
 * - lockedMajor(), the override: the major never moves, so a breaking change
 *   moves the minor; a line with no tag yet starts at `<major>.0.0`. The major
 *   is given, or with lockedMajorFromPhpRequirement() is composer.json's PHP
 *   line written without the dot (`^8.5` is 85), which is php-qa-ci's own
 *   scheme.
 *
 * Either takes a tag prefix (e.g. `v`): the tag is `<prefix><version>`, the
 * version in CHANGELOG.md has none, and a tag of any other shape is not a
 * release.
 *
 * @api
 */
final readonly class ReleaseVersionPolicy
{
    /** The first release under semantic versioning, as semver.org suggests for initial development. */
    public const string FIRST_VERSION = '0.1.0';

    /** A prefix git and a shell take as-is: empty, or a letter then letters, digits and `._/-`. */
    private const string TAG_PREFIX ='/^(?:[A-Za-z][A-Za-z0-9._\/-]*)?$/';

    /**
     * Prefer the named constructors; this one exists so the default policy
     * can be a parameter default (`new ReleaseVersionPolicy()`).
     *
     * @param string   $firstVersion       the first release when no tag is a release yet; a
     *                                     locked major starts at `<major>.0.0` instead
     * @param int|null $lockedMajor        the major every release keeps
     * @param bool     $lockMajorToPhpLine the major is composer.json's PHP line (`^8.5` is 85)
     */
    public function __construct(
        public string $tagPrefix = '',
        public string $firstVersion = self::FIRST_VERSION,
        public ?int $lockedMajor = null,
        public bool $lockMajorToPhpLine = false,
    ) {
        if (1 !== \Safe\preg_match(self::TAG_PREFIX, $tagPrefix)) {
            throw new InvalidArgumentException(\sprintf('A release tag prefix must start with a letter and hold only letters, digits, ".", "_", "/" or "-"; got "%s"', $tagPrefix));
        }

        if (1 !== \Safe\preg_match(ReleaseLine::VERSION_PATTERN, $firstVersion)) {
            throw new InvalidArgumentException(\sprintf('The first version must be a plain X.Y.Z version; got "%s"', $firstVersion));
        }

        if (null !== $lockedMajor && $lockedMajor < 0) {
            throw new InvalidArgumentException(\sprintf('A locked major must be 0 or more; got %d', $lockedMajor));
        }

        if (null !== $lockedMajor && $lockMajorToPhpLine) {
            throw new InvalidArgumentException('A release policy can lock the major to a number or to the PHP line, not both');
        }
    }

    public static function semanticVersioning(string $tagPrefix = '', string $firstVersion = self::FIRST_VERSION): self
    {
        return new self($tagPrefix, $firstVersion);
    }

    public static function lockedMajor(int $major, string $tagPrefix = ''): self
    {
        return new self($tagPrefix, lockedMajor: $major);
    }

    public static function lockedMajorFromPhpRequirement(string $tagPrefix = ''): self
    {
        return new self($tagPrefix, lockMajorToPhpLine: true);
    }

    /**
     * The releases this policy cuts for the project whose composer.json is
     * $composerJson; only the PHP-line policy reads it.
     */
    public function line(string $composerJson): ReleaseLine
    {
        $major = $this->lockMajorToPhpLine ? $this->phpLineMajor($composerJson) : $this->lockedMajor;

        return new ReleaseLine($major, $this->tagPrefix, $this->firstVersion);
    }

    private function phpLineMajor(string $composerJson): int
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
}
