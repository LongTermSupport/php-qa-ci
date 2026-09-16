<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli\Dto;

/**
 * What the command line asked for, after validation.
 *
 * `$tool` is the canonical name the `-t` token resolved to through the
 * registry, null for the full pipeline. `$path` is the `-p` path as given.
 * `$selectedToken` is the pseudo-tool token that was typed when it differs
 * from the tool actually run (`uniterate` selects `phpunit`).
 * `$agentMode` is true when `--agent-mode` was given or the environment asked
 * for it; the parser has already refused every combination that cannot work,
 * so a true here means the selected tool can produce a report.
 *
 * @internal
 */
final readonly class RunRequestDto
{
    public function __construct(
        public ?string $tool,
        public ?string $path,
        public ?string $selectedToken = null,
        public bool $agentMode = false,
    ) {
    }
}
