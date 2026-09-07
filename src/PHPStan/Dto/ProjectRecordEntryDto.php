<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

/**
 * One `ignoreErrors` entry from a resolved phpstan.neon, plus any comment
 * line found immediately above it in the source text (recovered by reading
 * the raw file, since the neon parser strips comments). Supports both the
 * structured `{message|identifier|path|count}` shape and a plain regex string.
 *
 * @internal
 */
final readonly class ProjectRecordEntryDto
{
    public function __construct(
        public ?string $identifier,
        public ?string $message,
        public ?string $path,
        public ?int $count,
        public ?string $raw,
        public ?string $justification,
    ) {
    }
}
