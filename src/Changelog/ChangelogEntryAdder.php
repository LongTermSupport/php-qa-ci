<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;

/**
 * Adds one `- ` entry to the `## Unreleased` section: after the last entry
 * of its heading, or under a new heading placed in canonical order (before
 * the first existing heading that ranks after it, else at the end of the
 * section). The rest of the file is left as it was.
 *
 * @api
 */
final readonly class ChangelogEntryAdder
{
    public function add(ChangelogDocumentDto $document, ChangelogHeadingEnum $heading, string $text): string
    {
        $text = trim($text);
        if ('' === $text || 1 === \Safe\preg_match('/[\r\n]/', $text)) {
            throw new ChangelogReleaseException('a changelog entry must be one non-empty line of text');
        }

        $entry = '- ' . $text;
        $lines = $document->lines;

        $existing = $document->block($heading);
        if (null !== $existing) {
            array_splice($lines, $existing->lastIndex + 1, 0, ['', $entry]);

            return implode("\n", $lines);
        }

        $newBlock = ['### ' . $heading->value, '', $entry];
        foreach ($document->blocks as $block) {
            if ($block->heading->rank() > $heading->rank()) {
                array_splice($lines, $block->headingIndex, 0, [...$newBlock, '']);

                return implode("\n", $lines);
            }
        }

        $after = $document->unreleasedIndex;
        for ($index = $document->sectionEnd - 1; $index > $document->unreleasedIndex; --$index) {
            if ('' !== trim($lines[$index])) {
                $after = $index;

                break;
            }
        }

        $next = $lines[$after + 1] ?? null;
        array_splice($lines, $after + 1, 0, null !== $next && '' !== trim($next) ? ['', ...$newBlock, ''] : ['', ...$newBlock]);

        return implode("\n", $lines);
    }
}
