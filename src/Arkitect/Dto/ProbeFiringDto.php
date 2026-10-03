<?php

declare(strict_types=1);

namespace LTS\PHPQA\Arkitect\Dto;

/**
 * One class the probed rule fired on: the class PHPArkitect named, the file
 * declaring it (project-relative where it can be traced, null where it cannot)
 * and the message PHPArkitect printed, which ends in the rule's `because`
 * clause.
 *
 * @internal
 */
final readonly class ProbeFiringDto
{
    public function __construct(
        public string $fqcn,
        public ?string $file,
        public string $message,
    ) {
    }
}
