<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;

/**
 * Releases the `## Unreleased` section: its body becomes a
 * `## <version> — <date>` section directly below a fresh, empty
 * `## Unreleased`. The blank lines around the body are normalised to one;
 * every other byte of the file is left as it was.
 *
 * @api
 */
final readonly class ChangelogReleaseWriter
{
    public function apply(ChangelogDocumentDto $document, string $version, string $date): string
    {
        if (1 !== \Safe\preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new ChangelogReleaseException(\sprintf('the version must be MAJOR.MINOR.PATCH digits; got "%s"', $version));
        }

        if (1 !== \Safe\preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) || !isset($parts[1], $parts[2], $parts[3]) || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new ChangelogReleaseException(\sprintf('the date must be a real YYYY-MM-DD date; got "%s"', $date));
        }

        if ($document->isEmpty()) {
            throw new ChangelogReleaseException(\sprintf('the "%s" section is empty: there is nothing to release', ChangelogParser::UNRELEASED_HEADING));
        }

        $section = '## ' . $version;
        foreach ($document->lines as $line) {
            $line = rtrim($line);
            if ($section === $line || str_starts_with($line, $section . ' ')) {
                throw new ChangelogReleaseException(\sprintf('CHANGELOG.md already has a "%s" section', $section));
            }
        }

        $lines  = $document->lines;
        $before = \array_slice($lines, 0, $document->unreleasedIndex + 1);
        $body   = trim(implode("\n", \array_slice($lines, $document->unreleasedIndex + 1, $document->sectionEnd - $document->unreleasedIndex - 1)));
        $rest   = \array_slice($lines, $document->sectionEnd);

        $released = implode("\n", $before) . "\n\n" . $section . ' — ' . $date . "\n\n" . $body . "\n";

        return [] === $rest ? $released : $released . "\n" . implode("\n", $rest);
    }
}
