<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

/**
 * The releases one project cuts, resolved from its ReleaseVersionPolicy: the
 * major it locks (null under semantic versioning), the tag prefix, and the
 * version a project with no release yet starts at.
 *
 * A release is a plain `X.Y.Z` with no leading zeros, and under a locked
 * major one whose major is that major; a tag is a release when it is the
 * prefix followed by one. Versions compare numerically, so `1.10.0` is newer
 * than `1.9.0`, and `1.4`, `1.4.2-rc1`, or `v1.4.2` where there is no prefix,
 * are not releases.
 *
 * @api
 */
final readonly class ReleaseLine
{
    public const string VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function __construct(
        public ?int $lockedMajor,
        public string $tagPrefix,
        private string $firstVersion,
    ) {
    }

    public function isRelease(string $version): bool
    {
        if (1 !== \Safe\preg_match(self::VERSION_PATTERN, $version, $matches) || !isset($matches[1])) {
            return false;
        }

        return null === $this->lockedMajor || (int)$matches[1] === $this->lockedMajor;
    }

    /** The version a tag releases, or null when the tag is not a release under this policy. */
    public function versionOfTag(string $tag): ?string
    {
        if (!str_starts_with($tag, $this->tagPrefix)) {
            return null;
        }

        $version = substr($tag, \strlen($this->tagPrefix));

        return $this->isRelease($version) ? $version : null;
    }

    public function tagOf(string $version): string
    {
        return $this->tagPrefix . $version;
    }

    /** The newest tag that is a release, or null when none is. */
    public function latestTag(string ...$tags): ?string
    {
        $latestTag     = null;
        $latestVersion = null;
        foreach ($tags as $tag) {
            $version = $this->versionOfTag($tag);
            if (null !== $version && (null === $latestVersion || version_compare($version, $latestVersion, '>'))) {
                $latestTag     = $tag;
                $latestVersion = $version;
            }
        }

        return $latestTag;
    }

    /** The version the newest release tag names, or null when no tag is a release. */
    public function latestVersion(string ...$tags): ?string
    {
        $latest = $this->latestTag(...$tags);

        return null === $latest ? null : $this->versionOfTag($latest);
    }

    /** The version a release asking for $bump is, given the tags that already exist. */
    public function next(ReleaseBumpEnum $bump, string ...$tags): string
    {
        $latest = $this->latestVersion(...$tags);
        if (null === $latest) {
            return $this->firstVersion();
        }

        [$major, $minor, $patch] = array_map(intval(...), explode('.', $latest));
        $movesMajor              = ReleaseBumpEnum::Major === $bump && null === $this->lockedMajor && $major > 0;

        return match (true) {
            $movesMajor                      => \sprintf('%d.0.0', $major + 1),
            ReleaseBumpEnum::Patch === $bump => \sprintf('%d.%d.%d', $major, $minor, $patch + 1),
            default                          => \sprintf('%d.%d.0', $major, $minor + 1),
        };
    }

    /** The version released when no tag is a release yet. */
    public function firstVersion(): string
    {
        return null === $this->lockedMajor ? $this->firstVersion : $this->lockedMajor . '.0.0';
    }

    /** The shape of a release tag, for messages: `vX.Y.Z`, `85.N.N`. */
    public function describe(): string
    {
        return $this->tagPrefix . (null === $this->lockedMajor ? 'X.Y.Z' : $this->lockedMajor . '.N.N');
    }
}
