<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use LTS\PHPQA\Pipeline\Process\SignalledChildren;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * A lane whose child a signal killed, or that exited with the signal's code
 * itself, as SymfonyProcessRunner records it, and which then reports a pass,
 * as a lane that reads only its child's output might, writing $stdout as its
 * terse verdict when given one. No process is spawned, so a SIGSEGV is
 * recorded without one.
 */
final class SignalledChildTool implements ToolInterface
{
    public int $runs = 0;

    public function __construct(
        private readonly string $name,
        private readonly SignalledChildren $signalled,
        private readonly int $signal,
        private readonly bool $exitedItself = false,
        private readonly string $stdout = '',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function identifier(): string
    {
        return 'phpqaci.' . $this->name;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        ++$this->runs;
        $context->writeln('[' . $this->name . ' ran]');
        $this->signalled->record(new SignalledChildDto(self::commandLine($this->name), $this->signal, $this->exitedItself));
        if ('' !== $this->stdout) {
            $context->stdout->writeln($this->stdout);
        }

        return ToolResultDto::passed();
    }

    /** The command line recorded for the lane's child. */
    public static function commandLine(string $name): string
    {
        return 'fake ' . $name;
    }
}
