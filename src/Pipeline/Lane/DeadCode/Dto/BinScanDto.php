<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DeadCode\Dto;

/**
 * What BinEntryPointScripts found under bin/: the PHP scripts the run would not read, and the
 * paths it could not read itself, either of which may hide code analysed by nothing.
 *
 * @internal
 */
final readonly class BinScanDto
{
    /**
     * @param list<string> $undeclared project-relative (`bin/<path>`), sorted
     * @param list<string> $unreadable project-relative (`bin/<path>`), sorted
     */
    public function __construct(
        public array $undeclared,
        public array $unreadable,
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->undeclared && [] === $this->unreadable;
    }
}
