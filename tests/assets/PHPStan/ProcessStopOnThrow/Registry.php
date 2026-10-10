<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** Stands in for the runner's registry of running children. */
final class Registry
{
    /** @var list<Process> */
    private array $processes = [];

    public function add(Process $process): void
    {
        $this->processes[] = $process;
    }

    public function remove(Process $process): void
    {
        $this->processes = array_values(array_filter(
            $this->processes,
            static fn (Process $held): bool => $held !== $process,
        ));
    }
}
