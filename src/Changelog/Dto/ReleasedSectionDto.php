<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

/**
 * A released `## <version> — <date>` section. `$title` is its heading without
 * the `## `; `$lines` is the whole file, which the block indexes point into.
 *
 * @api
 */
final readonly class ReleasedSectionDto
{
    /**
     * @param list<string>                             $lines
     * @param non-empty-list<ChangelogHeadingBlockDto> $blocks
     */
    public function __construct(
        public string $title,
        public array $lines,
        public array $blocks,
    ) {
    }
}
