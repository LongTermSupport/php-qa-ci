<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection\Dto;

/**
 * A mutant Infection counted as killed although no test ran: where it is
 * (`<file>:<line> <mutator>`) and the test process output that shows it.
 *
 * @internal
 */
final readonly class VacuousKillDto
{
    public function __construct(
        public string $mutant,
        public string $output,
    ) {
    }
}
