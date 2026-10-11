<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** A tearDown() that nothing calls: this is not a PHPUnit test case. */
final class NotATestCase
{
    private ?Process $child = null;

    public function tearDown(): void
    {
        $this->child?->stop(0);
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
    }
}
