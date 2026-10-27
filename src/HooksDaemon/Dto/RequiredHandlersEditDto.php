<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon\Dto;

/**
 * The outcome of enforcing the required handler settings on one config text:
 * the resulting text, what changed, and, when the layout could not be edited
 * in place, the setting that blocked it. The text is the input unchanged
 * unless `$changes` is non-empty.
 *
 * @internal
 */
final readonly class RequiredHandlersEditDto
{
    /**
     * @param list<string> $changes one line per setting added or updated
     */
    public function __construct(
        public string $yaml,
        public array $changes,
        public ?string $unsupported,
    ) {
    }
}
