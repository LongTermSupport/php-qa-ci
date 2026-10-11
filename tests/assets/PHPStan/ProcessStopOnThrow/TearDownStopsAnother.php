<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The tearDown stops a different property, and a local variable is out of its reach. */
final class TearDownStopsAnother extends TestCase
{
    private ?Process $child = null;

    private ?Process $other = null;

    /** The local it stops is not the local a test method starts, whatever their names. */
    protected function tearDown(): void
    {
        $process = $this->other;
        $process?->stop(0);
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }

    public function startsALocal(): void
    {
        $process = new Process(['sleep', '60']);
        $process->start();
        self::assertTrue($process->isRunning());
    }
}
