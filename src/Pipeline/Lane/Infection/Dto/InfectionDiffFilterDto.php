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
     * @param list<string>          $positionalPaths absolute paths, handed to Infection as positional arguments
     * @param list<string>          $relativePaths   the same files, project-relative, for the log line
     * @param array<string, string> $mirrored        source file => the changed test that brought it into scope
     * @param list<string>          $unmappedTests   changed test-directory files that mirror no source file
     */
    public function __construct(
        public array $positionalPaths,
        public array $relativePaths,
        public array $mirrored = [],
        public array $unmappedTests = [],
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
