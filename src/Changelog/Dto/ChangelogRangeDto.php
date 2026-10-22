<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

/**
 * Where the judged changes start: `$base` is a revision git accepts (a sha or
 * a tag), and `$description` says in words why it was chosen. `$sinceRelease`
 * is true when the range is the default branch's since its last release tag:
 * history already merged, which the check reports on but does not ask anyone
 * to rewrite.
 *
 * @api
 */
final readonly class ChangelogRangeDto
{
    public function __construct(
        public string $base,
        public string $description,
        public bool $sinceRelease = false,
    ) {
    }
}
