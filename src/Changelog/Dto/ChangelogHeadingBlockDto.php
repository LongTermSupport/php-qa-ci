<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;

/**
 * One `###` heading of a changelog section and its entries. `$headingIndex`
 * and `$lastIndex` are 0-based line indexes into the file: the heading line,
 * and the last line of its last entry.
 *
 * @api
 */
final readonly class ChangelogHeadingBlockDto
{
    /** @param non-empty-list<string> $entries each entry's text, continuation lines joined with "\n" */
    public function __construct(
        public ChangelogHeadingEnum $heading,
        public int $headingIndex,
        public int $lastIndex,
        public array $entries,
    ) {
    }
}
