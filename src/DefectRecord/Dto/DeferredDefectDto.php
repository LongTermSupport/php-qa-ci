<?php

declare(strict_types=1);

namespace LTS\PHPQA\DefectRecord\Dto;

/**
 * One Defect found and not fixed now (method specification section 2): what
 * is wrong, the Class where one is already apparent, where it was found, and
 * who decided not to fix it yet.
 *
 * @internal
 */
final readonly class DeferredDefectDto
{
    public function __construct(
        public string $defect,
        public ?string $class,
        public string $found,
        public string $deferredBy,
    ) {
    }
}
