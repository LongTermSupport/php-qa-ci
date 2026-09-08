<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli\Dto;

/**
 * What the command line asked for, after validation.
 *
 * @internal
 */
final readonly class RunRequestDto
{
    public function __construct(
        /** Canonical tool name (the `-t` token resolved through the registry), or null for the full pipeline. */
        public ?string $tool,
        /** The `-p` path as given, or null. */
        public ?string $path,
        public bool $json,
        /** The pseudo-tool token that was typed, when it differs from the target (uniterate). */
        public ?string $selectedToken = null,
    ) {
    }
}
