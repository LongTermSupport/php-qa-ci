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

    private function isOptOut(string $value): bool
    {
        if (1 !== \Safe\preg_match('/^none\s*(?:—|-)\s*(.*)$/iu', trim($value), $matches) || !isset($matches[1])) {
            return false;
        }

        $reason = trim($matches[1]);

        return \strlen($reason) >= self::MIN_REASON_LENGTH && \Safe\preg_match_all('/\S+/u', $reason) >= self::MIN_REASON_WORDS;
    }
}
