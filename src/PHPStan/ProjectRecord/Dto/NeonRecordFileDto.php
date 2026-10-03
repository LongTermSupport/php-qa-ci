<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord\Dto;

/**
 * One NEON file PHPStan reads for the project.
 *
 * `$path` is absolute and `$display` is the same file relative to the project
 * root (absolute when it lies outside it). `$neon` is the text, which keeps
 * the comments a justification lives in. `$declaredEntries` is the number of
 * `parameters.ignoreErrors` entries NEON decodes from it, whatever form they
 * are written in.
 *
 * @internal
 */
final readonly class NeonRecordFileDto
{
    public function __construct(
        public string $path,
        public string $display,
        public string $neon,
        public int $declaredEntries,
    ) {
    }
}
