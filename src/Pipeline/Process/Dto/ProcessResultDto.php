<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process\Dto;

use LTS\PHPQA\ComposerPlugin\ChildExit;

/**
 * What one external command produced.
 *
 * Two captures, because they answer different questions: `$output` is stdout
 * and stderr interleaved as they arrived, which is what a human or a log wants;
 * `$stdout` is stdout alone, which is what a caller parsing structured output
 * (PHPStan's `--json`) must use, since a PHP warning on stderr would otherwise
 * corrupt it.
 *
 * `$signal` is the signal that killed the child, when one did; `$exitCode` is
 * then 128 + the signal, as a shell reports it. A text that reports the exit
 * code says it with describeExit(), never by formatting `$exitCode` itself:
 * only the result knows whether a signal killed the child or the child exited
 * with a signal's code itself, relaying a crash below it.
 *
 * @api
 */
final readonly class ProcessResultDto
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $stdout,
        public ?int $signal = null,
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }

    /**
     * How the child ended, for a line that reports it: "exit 1"; "exit 143, killed by signal 15
     * (SIGTERM)" when a signal killed it; "exit 139, which it exited with itself: the code a
     * shell gives for signal 11 (SIGSEGV), so a process below it crashed ..."
     * when it exited with a signal's code itself.
     */
    public function describeExit(): string
    {
        $how = null === $this->signal ? ChildExit::exitedItself($this->exitCode) : ChildExit::killedBy($this->exitCode);

        return 'exit ' . $this->exitCode . $how;
    }
}
