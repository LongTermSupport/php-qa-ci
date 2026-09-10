<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection\Dto;

/**
 * The source files a diff-mode Infection run is scoped to.
 *
 * @internal
 */
final readonly class InfectionDiffFilterDto
{
    /**
     * @param list<string> $positionalPaths absolute paths, handed to Infection as positional arguments
     * @param list<string> $relativePaths   the same files as git reported them, for the log line
     */
    public function __construct(
        public array $positionalPaths,
        public array $relativePaths,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->positionalPaths;
    }

    /** The comma-joined display form the log line prints. */
    public function display(): string
    {
        return implode(',', $this->relativePaths);
    }
}
