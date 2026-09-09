<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

/**
 * Everything needed to run one external command.
 *
 * `$timeout` is in seconds and null means no limit. `$streamOutput` echoes the
 * output as it arrives, in addition to capturing it. `$lowPriority` runs the
 * process tree at the lowest CPU priority, which mutation testing uses so it
 * yields to everything else on the host.
 *
 * @api
 */
final readonly class ProcessSpecDto
{
    /**
     * @param list<string>          $command the argv, never a shell string
     * @param array<string, string> $env     variables added to the inherited environment
     */
    public function __construct(
        public array $command,
        public string $cwd,
        public array $env = [],
        public ?float $timeout = null,
        public bool $streamOutput = true,
        public bool $lowPriority = false,
    ) {
    }

    /** The command as a human would type it, for logs. */
    public function commandLine(): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => 1 === \Safe\preg_match('/^[A-Za-z0-9_\-.\/=:,+@%]+$/', $arg) ? $arg : escapeshellarg($arg),
            $this->command,
        ));
    }
}
