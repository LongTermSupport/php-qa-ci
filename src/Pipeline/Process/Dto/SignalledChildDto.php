<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

use LTS\PHPQA\ComposerPlugin\ChildExit;

/**
 * A child process that a signal killed, which the child itself could say
 * nothing about, or one that exited 139 to report a segfault below it: the
 * command as it was echoed, the signal, and whether the child exited with the
 * signal's code itself rather than being killed by it.
 *
 * @internal
 */
final readonly class SignalledChildDto
{
    public function __construct(
        public string $commandLine,
        public int $signal,
        public bool $exitedItself = false,
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

    /**
     * "`cmd` ended with exit code 143, killed by signal 15 (SIGTERM)", or for a child
     * that exited with the code itself "`cmd` ended with exit code 139, which it exited
     * with itself: the code a shell gives for signal 11 (SIGSEGV), so a
     * process below it crashed ...".
     */
    public function describe(): string
    {
        $exit = $this->exitedItself ? ChildExit::describeExitedItself($this->exitCode()) : ChildExit::describe($this->exitCode());

        return \sprintf('`%s` ended with %s', $this->commandLine, $exit);
    }
}
