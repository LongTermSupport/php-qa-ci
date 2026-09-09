<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

/**
 * What one external command produced.
 *
 * Two captures, because they answer different questions: `$output` is stdout
 * and stderr interleaved as they arrived, which is what a human or a log wants;
 * `$stdout` is stdout alone, which is what a caller parsing structured output
 * (PHPStan's `--json`) must use, since a PHP warning on stderr would otherwise
 * corrupt it.
 *
 * @api
 */
final readonly class ProcessResultDto
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $stdout,
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }
}
