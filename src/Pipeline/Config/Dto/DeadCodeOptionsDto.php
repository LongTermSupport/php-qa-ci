<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * What the deadCode lane runs with.
 *
 * Off unless a project opts in. `$entryPoints` are absolute paths to PHP
 * scripts the detector must analyse as entry points on top of src/ and tests/:
 * a `bin/` script has no `.php` suffix, so a directory scan never sees it and
 * every `main()` it calls is reported dead. The builder refuses to enable the
 * lane until a project has either listed them or said it has none, because a
 * forgotten list is indistinguishable from an empty one.
 *
 * @api
 */
final readonly class DeadCodeOptionsDto
{
    /** @param list<string> $entryPoints */
    public function __construct(
        public bool $enabled,
        public array $entryPoints,
    ) {
    }
}
