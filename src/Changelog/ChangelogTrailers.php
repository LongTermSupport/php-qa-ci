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
     * trailers. A line counts only when `Changelog:` starts it at column 0,
     * case for case; anything else is prose. Each of $trailers (git's
     * unfolded `Changelog` trailers of the same commit, one per line)
     * accounts for one body line whose value it starts with, so a folded
     * trailer accounts for its first line.
     *
     * @return list<string> the misplaced lines, trimmed
     */
    public function misplaced(string $body, string $trailers): array
    {
        $read = [];
        foreach (explode("\n", $trailers) as $trailer) {
            $separator = strpos($trailer, ':');
            if (false !== $separator) {
                $read[] = trim(substr($trailer, $separator + 1));
            }
        }

        $misplaced = [];
        foreach (explode("\n", $body) as $line) {
            if (!str_starts_with($line, ChangelogGit::TRAILER_KEY . ':')) {
                continue;
            }

            $value = trim(substr($line, \strlen(ChangelogGit::TRAILER_KEY) + 1));
            $match = array_find_key($read, static fn (string $trailer): bool => str_starts_with($trailer, $value));
            if (null === $match) {
                $misplaced[] = trim($line);

                continue;
            }

            unset($read[$match]);
        }

        return $misplaced;
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
