<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A Process whose input is, or may be, a Traversable iterates it while the
 * child is alive, inside start(), run(), mustRun() and wait(), callback or
 * not. The first group is reported; the second is clean.
 */
final class IteratorInput
{
    public function __construct(private readonly Process $process)
    {
    }

    public function generatorGivenBySetInput(Process $process): void
    {
        $process->setInput($this->lines());
        $process->run();
    }

    public function generatorGivenToTheConstructor(): void
    {
        $process = new Process(['cat'], null, null, $this->lines());
        $process->mustRun();
    }

    public function generatorGivenToTheFactoryByName(): void
    {
        $process = Process::fromShellCommandline('cat', input: $this->lines());
        $process->start();
        $process->wait();
    }

    /** Not provably a Traversable, so it may be one. */
    public function inputOfUnknownType(Process $process, mixed $input): void
    {
        $process?->setInput($input);
        $process->wait();
    }

    /** start() iterates the input itself, so the statement before the try is not enough. */
    public function startImmediatelyBeforeAStoppingTry(ChildProcess $process): void
    {
        $process->setInput(new \ArrayIterator(['a']));
        $process->start();
        try {
            $process->wait();
        } finally {
            $process->stop(0);
        }
    }

    public function inputStream(Process $process): void
    {
        $process->setInput(new InputStream());
        $process->run();
    }

    public function unpackedArguments(array $arguments): void
    {
        $process = new Process(...$arguments);
        $process->run();
    }

    /** The input is given here and the process is run elsewhere, where no finally can be matched. */
    public function inputGivenButNotRunHere(): void
    {
        $this->process->setInput($this->lines());
    }

    public function newProcessNotHeldInAVariable(): Process
    {
        return new Process(['cat'], null, null, $this->lines());
    }

    public function stringInput(Process $process): void
    {
        $process->setInput('text');
        $process->run();
    }

    public function nullInput(Process $process): void
    {
        $process->setInput(null);
        $process->mustRun();
    }

    public function stringInputToTheConstructor(): int
    {
        $process = new Process(['cat'], null, null, 'text');
        $named   = new Process(['cat'], input: 'text', timeout: 1.0);
        $none    = new Process(['cat'], null, null);
        $named->run();
        $none->run();

        return $process->run();
    }

    public function generatorRunInsideAStoppingTry(Process $process): void
    {
        $process->setInput($this->lines());
        try {
            $process->run();
        } finally {
            $process->stop(0);
        }
    }

    /** The iterator input is another process's, and so is the run that iterates it. */
    public function anotherProcessHasTheIteratorInput(Process $process, Process $other): void
    {
        $other->setInput($this->lines());
        try {
            $other->mustRun();
        } finally {
            $other->stop(0);
        }

        $process->run();
    }

    public function notAProcess(): void
    {
        new \SplFixedArray(3);
        Runner::fromShellCommandline('cat', null, null, $this->lines());
    }

    /** setInput() returns the process, so the variable it is assigned to has the input too. */
    public function theProcessSetInputReturns(Process $process): void
    {
        $copy = $process->setInput($this->lines());
        try {
            $process->run();
        } finally {
            $process->stop(0);
        }

        $copy->run();
    }

    public function aNewProcessReturnedFromAVariable(): Process
    {
        $process = new Process(['cat'], null, null, $this->lines());

        return $process;
    }

    /** The factory called through an instance: the class comes from the expression's type. */
    public function theFactoryThroughAnInstance(Process $process): void
    {
        $fed = $process::fromShellCommandline('cat', null, null, $this->lines());
        $fed->run();
    }

    /** Another function: the input inputGivenButNotRunHere() gives is not seen here. */
    public function runsThePropertyElsewhere(): void
    {
        $this->process->run();
    }

    /** @return \Generator<int, string> */
    private function lines(): \Generator
    {
        yield "a\n";
    }
}

/** Another class, with a method of the same name: the input IteratorInput gives is not seen here either. */
final class IteratorInputElsewhere
{
    public function __construct(private readonly Process $process)
    {
    }

    public function inputGivenButNotRunHere(): void
    {
        $this->process->run();
    }
}
