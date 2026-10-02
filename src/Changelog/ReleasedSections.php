<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;

/**
 * The `## <major>.<minor>.<patch>` sections a release wrote into CHANGELOG.md.
 *
 * A release lands in two steps: the release pull request moves
 * `## Unreleased` into a version section, then the release workflow tags the
 * commit that did so. Between the two, those entries are recorded but not
 * yet released, so the changelog lane counts a section the range base lacks
 * as recorded (since()), and the release workflow tags every section newer
 * than the line's newest tag (untagged()).
 *
 * A heading counts only as `## X.Y.Z` alone or followed by a space; `v85.1.0`,
 * `85.1` and `85.1.0-rc1` are not releases, as for tags.
 *
 * @api
 */
final readonly class ReleasedSections
{
    private const string VERSION_HEADING = '/^## (\d+\.\d+\.\d+)(?: |$)/';

    public function __construct(
        private ChangelogParser $parser = new ChangelogParser(),
        private ReleaseVersionCalculator $calculator = new ReleaseVersionCalculator(),
    ) {
    }

    /** @return list<string> the version of every version section, in file order */
    public function versions(string $markdown): array
    {
        $versions = [];
        foreach (explode("\n", $markdown) as $line) {
            if (1 === \Safe\preg_match(self::VERSION_HEADING, rtrim($line), $matches) && isset($matches[1])) {
                $versions[] = $matches[1];
            }
        }

        return $versions;
    }

    /**
     * Every version section $markdown has and $baseMarkdown does not, parsed;
     * every section when there is no base. An invalid one is an
     * InvalidChangelogException.
     *
     * @return list<ReleasedSectionDto>
     */
    public function since(string $markdown, ?string $baseMarkdown): array
    {
        $before = null === $baseMarkdown ? [] : $this->versions($baseMarkdown);
        $new    = array_values(array_diff($this->versions($markdown), $before));

        return array_map(fn (string $version): ReleasedSectionDto => $this->parser->release($markdown, $version), $new);
    }

    /**
     * The versions on line $major newer than its newest tag, oldest first:
     * the releases still to be tagged.
     *
     * @return list<string>
     */
    public function untagged(string $markdown, int $major, string ...$tags): array
    {
        $latest   = $this->calculator->latestOnLine($major, ...$tags);
        $untagged = array_values(array_filter(
            $this->versions($markdown),
            static fn (string $version): bool => str_starts_with($version, $major . '.')
                && (null === $latest || version_compare($version, $latest, '>')),
        ));
        usort($untagged, $this->older(...));

        return $untagged;
    }

    /** Ascending version order, for usort(). */
    private function older(string $left, string $right): int
    {
        return version_compare($left, $right);
    }
}
