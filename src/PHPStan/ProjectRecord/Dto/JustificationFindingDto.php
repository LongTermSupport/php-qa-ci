<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord\Dto;

/**
 * One ignoreErrors entry whose justification is missing or says nothing.
 *
 * `$line` is 1-based and points at the entry's leading `-` in the neon file.
 * `$entry` is the entry's first meaningful line, so the report can name it.
 * `$fault` says which of the two failures this is.
 *
 * @internal
 */
final readonly class JustificationFindingDto
{
    public function __construct(
        public int $line,
        public string $entry,
        public string $fault,
    ) {
    }
}
