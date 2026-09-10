<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner\Dto;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;

/**
 * The outcome of executing one tool, including whether the operator retried
 * it (a run with retries should be repeated from the top before being trusted).
 *
 * @internal
 */
final readonly class ExecutionDto
{
    public function __construct(
        public ToolResultDto $result,
        public bool $retried,
    ) {
    }
}
