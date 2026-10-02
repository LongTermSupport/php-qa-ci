<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;

/**
 * Reads CHANGELOG.md's `## Unreleased` section, or one released section, into
 * headings and entries, and refuses anything it would otherwise have to guess
 * at: a missing or repeated section, a heading outside ChangelogHeadingEnum, a
 * repeated or empty heading, text outside a heading, and text under a heading
 * that is not a `- ` list entry. Every problem is reported together, with its
 * line number.
 *
 * An entry is a line opening `- ` (or `* `) plus the indented lines that
 * continue it. A section runs to the next `##` (or `#`) heading.
 *
 * @api
 */
final readonly class ChangelogParser
{
    public const string UNRELEASED_HEADING = '## Unreleased';

    private const string SECTION_PREFIX = '## ';

    private const string HEADING_PREFIX = '### ';

    public function parse(string $markdown): ChangelogDocumentDto
    {
        $problems = [];
        $document = $this->document($markdown, $problems);
        if ($document instanceof ChangelogDocumentDto && [] === $problems) {
            return $document;
        }

        throw InvalidChangelogException::withProblems(...$problems);
    }

    /** The document, or null when it is invalid (parse() says why). */
    public function parseOrNull(string $markdown): ?ChangelogDocumentDto
    {
        $problems = [];
        $document = $this->document($markdown, $problems);

        return [] === $problems ? $document : null;
    }

    /** The `## <version> — <date>` section a release wrote. */
    public function release(string $markdown, string $version): ReleasedSectionDto
    {
        $lines  = explode("\n", $markdown);
        $prefix = self::SECTION_PREFIX . $version;
        foreach ($lines as $index => $line) {
            $line = rtrim($line);
            if ($prefix !== $line && !str_starts_with($line, $prefix . ' ')) {
                continue;
            }

            $problems = [];
            $end      = $this->sectionEnd($index, ...$lines);
            $blocks   = $this->blocks($index + 1, $end, $problems, ...$lines);
            if ([] === $blocks && [] === $problems) {
                $problems[] = \sprintf('line %d: the "%s" section has no entries', $index + 1, $line);
            }

            if ([] !== $problems) {
                throw InvalidChangelogException::withProblems(...$problems);
            }

            return new ReleasedSectionDto(substr($line, \strlen(self::SECTION_PREFIX)), $lines, $blocks);
        }

        throw InvalidChangelogException::withProblems(\sprintf('no "%s" section', $prefix));
    }

    /**
     * @param list<string> $problems accumulator, by reference
     */
    private function document(string $markdown, array &$problems): ?ChangelogDocumentDto
    {
        $lines = explode("\n", $markdown);
        $start = null;
        foreach ($lines as $index => $line) {
            if (self::UNRELEASED_HEADING !== rtrim($line)) {
                continue;
            }

            if (null === $start) {
                $start = $index;

                continue;
            }

            $problems[] = \sprintf('line %d: "%s" repeats the section at line %d', $index + 1, self::UNRELEASED_HEADING, $start + 1);
        }

        if (null === $start) {
            $problems[] = \sprintf('no "%s" section', self::UNRELEASED_HEADING);

            return null;
        }

        $end    = $this->sectionEnd($start, ...$lines);
        $blocks = $this->blocks($start + 1, $end, $problems, ...$lines);

        return new ChangelogDocumentDto($lines, $start, $end, $blocks);
    }

    private function sectionEnd(int $start, string ...$lines): int
    {
        $count = \count($lines);
        for ($index = $start + 1; $index < $count; ++$index) {
            if (str_starts_with($lines[$index], self::SECTION_PREFIX) || str_starts_with($lines[$index], '# ')) {
                return $index;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $problems accumulator, by reference
     *
     * @return list<ChangelogHeadingBlockDto>
     */
    private function blocks(int $from, int $to, array &$problems, string ...$lines): array
    {
        $blocks       = [];
        $seen         = [];
        $underHeading = false;
        $heading      = null;
        $headingIndex = 0;
        $entries      = [];
        $last         = 0;
        for ($index = $from; $index < $to; ++$index) {
            $line = $lines[$index];
            if ('' === trim($line)) {
                continue;
            }

            if (str_starts_with($line, self::HEADING_PREFIX)) {
                $this->close($heading, $headingIndex, $last, $blocks, $problems, ...$entries);
                $underHeading = true;
                $heading      = $this->heading($line, $index, $seen, $problems);
                $headingIndex = $index;
                $last         = $index;
                $entries      = [];

                continue;
            }

            if (str_starts_with($line, '#')) {
                $problems[] = \sprintf('line %d: "%s" is not an allowed heading; only "###" headings belong in the section', $index + 1, rtrim($line));

                continue;
            }

            if (!$underHeading) {
                $problems[] = \sprintf('line %d: text outside a "###" heading: "%s"', $index + 1, rtrim($line));

                continue;
            }

            if (!$heading instanceof ChangelogHeadingEnum) {
                continue;
            }

            if (1 === \Safe\preg_match('/^[-*] /', $line)) {
                $entries[] = rtrim($line);
                $last      = $index;

                continue;
            }

            $lastEntry = array_key_last($entries);
            if (null !== $lastEntry && 1 === \Safe\preg_match('/^\s/', $line)) {
                $entries[$lastEntry] .= "\n" . rtrim($line);
                $last = $index;

                continue;
            }

            $problems[] = \sprintf('line %d: under "### %s" but not a "- " list entry: "%s"', $index + 1, $heading->value, rtrim($line));
        }

        $this->close($heading, $headingIndex, $last, $blocks, $problems, ...$entries);

        return $blocks;
    }

    /**
     * The heading a `###` line names, or null (with the problem recorded) when
     * it is not an allowed heading or repeats one.
     *
     * @param array<string, int> $seen     heading value => line index, by reference
     * @param list<string>       $problems accumulator, by reference
     */
    private function heading(string $line, int $index, array &$seen, array &$problems): ?ChangelogHeadingEnum
    {
        $label   = trim(substr($line, \strlen(self::HEADING_PREFIX)));
        $heading = ChangelogHeadingEnum::tryFrom($label);
        if (null === $heading) {
            $problems[] = \sprintf(
                'line %d: "%s" is not an allowed heading; use one of: %s',
                $index + 1,
                self::HEADING_PREFIX . $label,
                implode(', ', array_map(static fn (ChangelogHeadingEnum $allowed): string => $allowed->value, ChangelogHeadingEnum::cases())),
            );

            return null;
        }

        if (isset($seen[$heading->value])) {
            $problems[] = \sprintf('line %d: "%s" repeats the heading at line %d', $index + 1, self::HEADING_PREFIX . $label, $seen[$heading->value] + 1);

            return null;
        }

        $seen[$heading->value] = $index;

        return $heading;
    }

    /**
     * @param list<ChangelogHeadingBlockDto> $blocks   accumulator, by reference
     * @param list<string>                   $problems accumulator, by reference
     */
    private function close(?ChangelogHeadingEnum $heading, int $headingIndex, int $last, array &$blocks, array &$problems, string ...$entries): void
    {
        if (!$heading instanceof ChangelogHeadingEnum) {
            return;
        }

        if ([] === $entries) {
            $problems[] = \sprintf('line %d: "%s" has no entries', $headingIndex + 1, self::HEADING_PREFIX . $heading->value);

            return;
        }

        $blocks[] = new ChangelogHeadingBlockDto($heading, $headingIndex, $last, array_values($entries));
    }
}
