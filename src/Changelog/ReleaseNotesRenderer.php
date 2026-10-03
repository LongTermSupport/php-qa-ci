<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;

/**
 * The annotation of a release tag: the section's title, a BREAKING line
 * first when the release changes or removes behaviour, then each heading
 * with its entries exactly as the changelog has them.
 *
 * Headings are written as setext (`Fixed` over `-----`), not `### Fixed`:
 * git's default tag-message cleanup strips every line opening `#` as a
 * comment, so an ATX heading would silently vanish from the tag.
 *
 * @api
 */
final readonly class ReleaseNotesRenderer
{
    public function render(ReleasedSectionDto $section): string
    {
        $notes = $section->title . "\n\n";

        $breaking = [];
        foreach ($section->blocks as $block) {
            if ($block->heading->isBreaking()) {
                $breaking[] = '"' . $block->heading->value . '"';
            }
        }

        if ([] !== $breaking) {
            $notes .= \sprintf(
                "BREAKING: this release changes or removes behaviour a consuming project may rely on. Read %s below before upgrading.\n\n",
                implode(' and ', $breaking),
            );
        }

        $headings = [];
        foreach ($section->blocks as $block) {
            $label      = $block->heading->value;
            $body       = \array_slice($section->lines, $block->headingIndex + 1, $block->lastIndex - $block->headingIndex);
            $headings[] = $label . "\n" . str_repeat('-', \Safe\preg_match_all('/./u', $label)) . "\n\n" . trim(implode("\n", $body), "\n") . "\n";
        }

        return $notes . implode("\n", $headings);
    }
}
