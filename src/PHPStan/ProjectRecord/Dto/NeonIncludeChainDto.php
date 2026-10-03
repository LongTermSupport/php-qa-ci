<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord\Dto;

/**
 * The NEON files reached from a project's phpstan.neon through `includes:`,
 * in PHPStan's merge order (a file's includes before the file, so the entry
 * file is last), and one sentence for each include that
 * could not be followed. A non-empty `$problems` means part of the chain was
 * not read, so it cannot be called justified.
 *
 * @internal
 */
final readonly class NeonIncludeChainDto
{
    /**
     * @param list<NeonRecordFileDto> $files
     * @param list<string>            $problems
     */
    public function __construct(
        public array $files,
        public array $problems,
    ) {
    }
}
