<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Process\Process;

/** A tearDown() and an #[After] method a test case takes from a trait. */
trait StopsTheChildAfterEachTest
{
    protected ?Process $child = null;

    protected ?Process $other = null;

    protected function tearDown(): void
    {
        $this->child?->stop(0);
    }

    #[After]
    public function stopTheOther(): void
    {
        $this->other?->stop(0);
    }
}
