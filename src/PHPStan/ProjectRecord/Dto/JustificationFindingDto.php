<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord\Dto;

/**
 * One ignoreErrors entry whose justification is missing or says nothing.
 *
 * @internal
 */
final readonly class JustificationFindingDto
{
    public function __construct(
        /** 1-based line of the entry's leading `-` in the neon file. */
        public int $line,
        /** The entry's first meaningful line, so the report names it. */
        public string $entry,
        public string $fault,
    ) {
    }
}
