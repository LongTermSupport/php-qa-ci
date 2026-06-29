<?php

declare(strict_types=1);

namespace LTS\PHPQA\SensitiveParameter;

/**
 * Immutable result of scanning a directory for #[\SensitiveParameter] usages.
 *
 * @see SensitiveParameterUsageScanner
 */
final readonly class SensitiveParameterUsageResult
{
    /**
     * @param int          $count     total number of #[\SensitiveParameter] occurrences found
     * @param list<string> $locations human-readable "file:line" locations, one per occurrence
     */
    public function __construct(
        public int $count,
        public array $locations,
    ) {
    }

    public function hasUsage(): bool
    {
        return $this->count > 0;
    }
}
