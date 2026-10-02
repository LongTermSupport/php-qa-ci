<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;

/**
 * A changelog whose `## Unreleased` section parsed as valid.
 *
 * `$lines` is the whole file split on "\n", so a rewrite can change the
 * section and leave every other byte as it was. `$unreleasedIndex` is the
 * section's heading line and `$sectionEnd` the index of the line that ends it
 * (the next `##` heading, or the line count when it runs to the end).
 *
 * @api
 */
final readonly class ChangelogDocumentDto
{
    /**
     * @param list<string>                   $lines
     * @param list<ChangelogHeadingBlockDto> $blocks in file order
     */
    public function __construct(
        public array $lines,
        public int $unreleasedIndex,
        public int $sectionEnd,
        public array $blocks,
    ) {
    }

    /** No entries, so no release is due. */
    public function isEmpty(): bool
    {
        return [] === $this->blocks;
    }

    /** What the section does to the next version: null when it is empty. */
    public function bump(): ?ReleaseBumpEnum
    {
        if ($this->isEmpty()) {
            return null;
        }

        foreach ($this->blocks as $block) {
            if (ReleaseBumpEnum::Minor === $block->heading->bump()) {
                return ReleaseBumpEnum::Minor;
            }
        }

        return ReleaseBumpEnum::Patch;
    }

    public function isBreaking(): bool
    {
        foreach ($this->blocks as $block) {
            if ($block->heading->isBreaking()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every entry's text, continuation lines included */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->blocks as $block) {
            foreach ($block->entries as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function block(ChangelogHeadingEnum $heading): ?ChangelogHeadingBlockDto
    {
        foreach ($this->blocks as $block) {
            if ($heading === $block->heading) {
                return $block;
            }
        }

        return null;
    }
}
