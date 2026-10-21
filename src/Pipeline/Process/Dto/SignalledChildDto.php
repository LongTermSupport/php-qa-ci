<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

use LTS\PHPQA\ComposerPlugin\ChildExit;

/**
 * A child process that a signal killed, which the child itself could say
 * nothing about, or one that exited 139 to report a segfault below it: the
 * command as it was echoed, and the signal.
 *
 * @internal
 */
final readonly class SignalledChildDto
{
    public function __construct(
        public string $commandLine,
        public int $signal,
    ) {
    }

    /** The exit code a shell would have reported: 128 + the signal. */
    public function exitCode(): int
    {
        return ChildExit::exitCodeOfSignal($this->signal);
    }

    public function isSegmentationFault(): bool
    {
        return ChildExit::isSegmentationFault($this->exitCode());
    }

    /** "`cmd` ended with exit code 143, killed by signal 15 (SIGTERM)". */
    public function describe(): string
    {
        return \sprintf('`%s` ended with %s', $this->commandLine, ChildExit::describe($this->exitCode()));
    }
}
