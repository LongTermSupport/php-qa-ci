<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

/**
 * @api
 */
final readonly class ProcessResultDto
{
    public function __construct(
        public int $exitCode,
        /** stdout and stderr interleaved as they arrived. */
        public string $output,
        public string $stdout,
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }
}
