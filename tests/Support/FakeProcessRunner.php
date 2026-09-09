<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LogicException;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * Records every spec a tool asks to run and answers from a queue of canned
 * results, so a tool's argv, cwd, env and reaction to exit codes are tested
 * without spawning anything.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<ProcessSpecDto> */
    public array $specs = [];

    /** @var list<ProcessResultDto> */
    private array $queue = [];

    public function willReturn(ProcessResultDto ...$results): self
    {
        $this->queue = [...$this->queue, ...array_values($results)];

        return $this;
    }

    public function willSucceed(string $output = ''): self
    {
        return $this->willReturn(new ProcessResultDto(0, $output, $output));
    }

    public function willFail(int $exitCode = 1, string $output = ''): self
    {
        return $this->willReturn(new ProcessResultDto($exitCode, $output, $output));
    }

    public function run(ProcessSpecDto $spec): ProcessResultDto
    {
        $this->specs[] = $spec;
        $result        = array_shift($this->queue);
        if (null === $result) {
            throw new LogicException('FakeProcessRunner has no queued result for: ' . $spec->commandLine());
        }

        return $result;
    }

    public function lastSpec(): ProcessSpecDto
    {
        $last = array_last($this->specs);
        if (null === $last) {
            throw new LogicException('FakeProcessRunner ran nothing');
        }

        return $last;
    }

    /** @return list<string> the argv of every spec run, joined per spec */
    public function commandLines(): array
    {
        return array_map(static fn (ProcessSpecDto $spec): string => $spec->commandLine(), $this->specs);
    }
}
