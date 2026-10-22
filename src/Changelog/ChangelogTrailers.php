<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ChangelogTrailerVerdictDto;

/**
 * Judges `Changelog:` commit trailers. The one accepted value is
 * `none — <reason>` (an em dash or a hyphen), stating that no consuming
 * project could notice the change; the reason must say why in at least two
 * words and eight characters, so a bare `none` or `none — wip` is refused.
 *
 * @api
 */
final readonly class ChangelogTrailers
{
    private const int MIN_REASON_LENGTH = 8;

    private const int MIN_REASON_WORDS = 2;

    private const string KEY_PATTERN = '/^' . ChangelogGit::TRAILER_KEY . '[ \t]*:(.*)$/i';

    public function verdict(string ...$values): ChangelogTrailerVerdictDto
    {
        $optsOut = false;
        $invalid = [];
        foreach ($values as $value) {
            if ($this->isOptOut($value)) {
                $optsOut = true;

                continue;
            }

            $invalid[] = $value;
        }

        return new ChangelogTrailerVerdictDto($optsOut, $invalid);
    }

    /**
     * The `Changelog:` lines of a commit body that git did not read as
     * trailers. A line counts when it starts at column 0 with the key as git
     * matches it: in any case, with spaces or tabs allowed before the colon.
     * Anything else is prose. Each of $trailers (git's unfolded `Changelog`
     * trailers of the same commit, one per line) accounts for the one body
     * line whose whole value, unfolded over its indented continuation lines,
     * equals its own, whitespace runs aside. The body is walked from the
     * bottom, where the trailer block is, so of two equal lines the one
     * above the block is the one named.
     *
     * @return list<string> the misplaced lines, trimmed, top to bottom
     */
    public function misplaced(string $body, string $trailers): array
    {
        $read = [];
        foreach (explode("\n", $trailers) as $trailer) {
            $separator = strpos($trailer, ':');
            if (false !== $separator) {
                $read[] = $this->normalised(substr($trailer, $separator + 1));
            }
        }

        $lines     = explode("\n", $body);
        $misplaced = [];
        foreach (array_reverse($this->declarations(...$lines), true) as $index => $value) {
            $match = array_search($value, $read, true);
            if (false === $match) {
                $misplaced[] = trim($lines[$index]);

                continue;
            }

            unset($read[$match]);
        }

        return array_reverse($misplaced);
    }

    /**
     * Each `Changelog` line's normalised value, unfolded over the indented
     * lines that follow it.
     *
     * @return array<int, string> line index => value
     */
    private function declarations(string ...$lines): array
    {
        $lines        = array_values($lines);
        $declarations = [];
        foreach ($lines as $index => $line) {
            if (1 !== \Safe\preg_match(self::KEY_PATTERN, $line, $matches) || !isset($matches[1])) {
                continue;
            }

            $value = $matches[1];
            for ($next = $index + 1; isset($lines[$next]) && 1 === \Safe\preg_match('/^[ \t]+\S/', $lines[$next]); ++$next) {
                $value .= ' ' . $lines[$next];
            }

            $declarations[$index] = $this->normalised($value);
        }

        return $declarations;
    }

    private function normalised(string $value): string
    {
        return implode(' ', array_filter(\Safe\preg_split('/\s+/', trim($value)), is_string(...)));
    }

    private function isOptOut(string $value): bool
    {
        if (1 !== \Safe\preg_match('/^none\s*(?:—|-)\s*(.*)$/iu', trim($value), $matches) || !isset($matches[1])) {
            return false;
        }

        $reason = trim($matches[1]);

        return \strlen($reason) >= self::MIN_REASON_LENGTH && \Safe\preg_match_all('/\S+/u', $reason) >= self::MIN_REASON_WORDS;
    }
}
