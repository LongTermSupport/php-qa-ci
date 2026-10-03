<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use Closure;
use LTS\PHPQA\Changelog\Dto\ChangelogCheckResultDto;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ChangelogRangeDto;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;

/**
 * The changelog lane's judgement, in three checks:
 *
 *  1. CHANGELOG.md's `## Unreleased` section is valid (ChangelogParser).
 *  2. Coverage: when a watched path changed in the range
 *     (ChangelogRangeResolver), the record gained an entry, or a commit in
 *     the range carries a valid `Changelog: none — <reason>` trailer.
 *  3. A runtime requirement added or re-constrained in composer.json is
 *     recorded under `### Changed — breaking`.
 *
 * The record is `## Unreleased` plus any version section the range base does
 * not have (ReleasedSections::since()), so the merged release pull request
 * passes before its tag exists.
 *
 * History it cannot read fails the check with the fetch that would supply it;
 * it never passes on no evidence.
 *
 * @api
 */
final readonly class ChangelogCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.changelog';

    public const string CHANGELOG = 'CHANGELOG.md';

    private const string COMPOSER_JSON = 'composer.json';

    private const int LISTED_FILES = 20;

    private const string WATCHED = 'watched ';

    public function __construct(
        private ChangelogParser $parser = new ChangelogParser(),
        private ChangelogRangeResolver $ranges = new ChangelogRangeResolver(),
        private ChangelogTrailers $trailers = new ChangelogTrailers(),
        private ComposerRequirementChanges $requirements = new ComposerRequirementChanges(),
        private ReleasedSections $released = new ReleasedSections(),
    ) {
    }

    /** @param ReleaseVersionPolicy $policy which tags are releases, and so where the default branch's range starts */
    public function check(
        string $projectRoot,
        ChangelogGit $git,
        GitBranches $branches,
        EnvironmentReader $env,
        WatchedPaths $watched,
        ReleaseVersionPolicy $policy = new ReleaseVersionPolicy(),
    ): ChangelogCheckResultDto {
        $changelog = $this->read($projectRoot . '/' . self::CHANGELOG);
        if (null === $changelog) {
            return new ChangelogCheckResultDto([], ['no ' . self::CHANGELOG . ' in the project root']);
        }

        try {
            $head = $this->parser->parse($changelog);
        } catch (InvalidChangelogException $invalidChangelogException) {
            return new ChangelogCheckResultDto([\sprintf('%s "%s" is invalid.', self::CHANGELOG, ChangelogParser::UNRELEASED_HEADING)], $invalidChangelogException->problems);
        }

        $report = [$this->validity($head)];
        try {
            if ($git->isShallow()) {
                throw new ChangelogHistoryException('this is a shallow clone, so the history the check needs is missing; fetch it (`git fetch --unshallow`, or `fetch-depth: 0` on actions/checkout)');
            }

            $composer = $this->read($projectRoot . '/' . self::COMPOSER_JSON);
            $range    = $this->ranges->resolve($git, $branches, $env, $composer ?? '{}', $policy);
            $report[] = 'Range: ' . $range->description . '.';

            $baseRead      = false;
            $baseChangelog = null;
            $base          = static function () use (&$baseRead, &$baseChangelog, $git, $range): ?string {
                if (!$baseRead) {
                    $baseChangelog = $git->fileAt($range->base, self::CHANGELOG);
                    $baseRead      = true;
                }

                return $baseChangelog;
            };

            $problems = [];
            $coverage = $this->coverage($head, $changelog, $base, $git, $range, $watched);
            if (str_contains($coverage, "\n")) {
                $problems[] = $coverage;
            } else {
                $report[] = $coverage;
            }

            $requirementChanges = $this->requirements->between($git->fileAt($range->base, self::COMPOSER_JSON), $composer);
            if ([] !== $requirementChanges && !$this->recordsBreaking($head, $changelog, $base)) {
                $problems[] = \sprintf(
                    'composer.json "require" changed %s (%s), and "%s" has no "### %s" entry: a new or tightened requirement breaks every consumer that cannot meet it, so record it there.',
                    $range->description,
                    implode('; ', $requirementChanges),
                    ChangelogParser::UNRELEASED_HEADING,
                    ChangelogHeadingEnum::ChangedBreaking->value,
                );
            }

            return new ChangelogCheckResultDto($report, $problems);
        } catch (ChangelogHistoryException|ChangelogReleaseException|InvalidChangelogException $exception) {
            return new ChangelogCheckResultDto($report, [$exception->getMessage()]);
        }
    }

    private function validity(ChangelogDocumentDto $head): string
    {
        $count = \count($head->entries());
        $bump  = match ($head->bump()) {
            ReleaseBumpEnum::Major => 'the next release is breaking',
            ReleaseBumpEnum::Minor => 'the next release bumps the minor',
            ReleaseBumpEnum::Patch => 'the next release bumps the patch',
            null                   => 'no release is due',
        };

        return \sprintf(
            '%s "%s" is valid: %s, %s.',
            self::CHANGELOG,
            ChangelogParser::UNRELEASED_HEADING,
            0 === $count ? 'no entries' : $count . (1 === $count ? ' entry' : ' entries'),
            $bump,
        );
    }

    /**
     * The blocks that record the range's changes: `## Unreleased`, plus every
     * version section the base does not have (released, not yet tagged).
     *
     * @return list<ChangelogHeadingBlockDto>
     */
    private function recorded(ChangelogDocumentDto $head, string $changelog, ?string $baseChangelog): array
    {
        $blocks = $head->blocks;
        foreach ($this->released->since($changelog, $baseChangelog) as $section) {
            array_push($blocks, ...$section->blocks);
        }

        return $blocks;
    }

    /** @param Closure(): ?string $base the base's CHANGELOG.md, read once and only when needed */
    private function recordsBreaking(ChangelogDocumentDto $head, string $changelog, Closure $base): bool
    {
        if ($head->block(ChangelogHeadingEnum::ChangedBreaking) instanceof ChangelogHeadingBlockDto) {
            return true;
        }

        return array_any($this->recorded($head, $changelog, $base()), static fn (ChangelogHeadingBlockDto $block): bool => ChangelogHeadingEnum::ChangedBreaking === $block->heading);
    }

    /**
     * One report line when the range is covered; a multi-line problem when it is not.
     *
     * @param Closure(): ?string $base the base's CHANGELOG.md, read once and only when needed
     */
    private function coverage(ChangelogDocumentDto $head, string $changelog, Closure $base, ChangelogGit $git, ChangelogRangeDto $range, WatchedPaths $watched): string
    {
        $changed        = $git->changedFiles($range->base);
        $watchedChanged = $watched->matching(...$changed);
        if ([] === $watchedChanged) {
            return \sprintf('%s changed, none of them watched (%s): no entry needed.', $this->files(\count($changed)), implode(', ', $watched->paths()));
        }

        $baseChangelog = $base();
        $gained        = $this->gainedEntries($baseChangelog, ...$this->recorded($head, $changelog, $baseChangelog));
        if ($gained > 0) {
            return \sprintf(
                '%s changed; recorded by %d new "%s" %s.',
                $this->files(\count($watchedChanged), self::WATCHED),
                $gained,
                ChangelogParser::UNRELEASED_HEADING,
                1 === $gained ? 'entry' : 'entries',
            );
        }

        $verdict = $this->trailers->verdict(...$git->trailers($range->base));
        if ($verdict->optsOut) {
            return \sprintf('%s changed; a "%s: none — <reason>" trailer states no entry is needed.', $this->files(\count($watchedChanged), self::WATCHED), ChangelogGit::TRAILER_KEY);
        }

        $problem = \sprintf(
            '%s changed %s with no new "%s" entry and no "%s: none — <reason>" trailer:',
            $this->files(\count($watchedChanged), self::WATCHED),
            $range->description,
            ChangelogParser::UNRELEASED_HEADING,
            ChangelogGit::TRAILER_KEY,
        );
        foreach (\array_slice($watchedChanged, 0, self::LISTED_FILES) as $file) {
            $problem .= "\n    " . $file;
        }

        if (\count($watchedChanged) > self::LISTED_FILES) {
            $problem .= \sprintf("\n    … and %d more", \count($watchedChanged) - self::LISTED_FILES);
        }

        $problem .= \sprintf(
            "\n  Record the change under the right heading of \"%s\" in %s (vendor/bin/changelog-release add-entry <heading> \"<text>\"),"
            . "\n  or, when no consuming project could notice it, give one commit in the range the trailer"
            . "\n    %s: none — <why no consuming project could notice>",
            ChangelogParser::UNRELEASED_HEADING,
            self::CHANGELOG,
            ChangelogGit::TRAILER_KEY,
        );
        if ([] !== $verdict->invalid) {
            $problem .= "\n  Ignored for want of a reason: " . implode(', ', array_map(static fn (string $value): string => '"' . $value . '"', $verdict->invalid));
        }

        return $problem;
    }

    /**
     * Recorded entries that were not in the base's `## Unreleased`. An
     * unreadable base section falls back to "entries its text does not
     * contain", so a base that was itself invalid still measures something.
     */
    private function gainedEntries(?string $baseChangelog, ChangelogHeadingBlockDto ...$recorded): int
    {
        $entries = array_merge(...array_map(static fn (ChangelogHeadingBlockDto $block): array => $block->entries, $recorded));
        if (null === $baseChangelog) {
            return \count($entries);
        }

        $base = $this->parser->parseOrNull($baseChangelog);
        if (!$base instanceof ChangelogDocumentDto) {
            return \count(array_filter($entries, static fn (string $entry): bool => !str_contains($baseChangelog, $entry)));
        }

        $remaining = $base->entries();
        $gained    = 0;
        foreach ($entries as $entry) {
            $index = array_search($entry, $remaining, true);
            if (false === $index) {
                ++$gained;

                continue;
            }

            unset($remaining[$index]);
        }

        return $gained;
    }

    private function files(int $count, string $kind = ''): string
    {
        return $count . ' ' . $kind . (1 === $count ? 'file' : 'files');
    }

    private function read(string $path): ?string
    {
        return is_file($path) ? \Safe\file_get_contents($path) : null;
    }
}
