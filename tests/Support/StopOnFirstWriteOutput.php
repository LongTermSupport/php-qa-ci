<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Stands in for the interrupt handler: the first time the child writes, stop
 * everything that is running.
 *
 * @internal
 */
final class StopOnFirstWriteOutput extends BufferedOutput
{
    public int $stopped = 0;

    public function __construct(private readonly RunningProcesses $running)
    {
        parent::__construct();
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite($message, $newline);
        if (0 === $this->stopped && str_contains($message, 'started')) {
            $this->stopped = $this->running->stopAll(5.0);
        }
    }
}
