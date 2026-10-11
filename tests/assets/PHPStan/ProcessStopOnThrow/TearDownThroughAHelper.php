<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** tearDown() and an #[After] method that stop the children through a helper of the class. */
final class TearDownThroughAHelper extends TestCase
{
    private ?Process $child = null;

    private ?Process $other = null;

    protected function tearDown(): void
    {
        $this->stopChild();
        parent::tearDown();
    }

    #[After]
    public function afterEach(): void
    {
        static::stopOther();
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
    }

    public function startsTheOther(): void
    {
        $this->other = new Process(['sleep', '60']);
        $this->other->start();
    }

    private function stopChild(): void
    {
        $this->child?->stop(0);
    }

    /** Called as static::stopOther(), which on an instance method still runs with $this. */
    private function stopOther(): void
    {
        $this->other?->stop(0);
    }
}
