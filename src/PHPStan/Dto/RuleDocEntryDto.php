<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

/**
 * One row of the bundled rule index, resolved to files on disk.
 *
 * @internal
 */
final readonly class RuleDocEntryDto
{
    public function __construct(
        public string $identifier,
        public string $ruleClass,
        public string $summary,
        public string $bundle,
        public string $sourcePath,
        public ?string $docPath,
    ) {
    }
}
