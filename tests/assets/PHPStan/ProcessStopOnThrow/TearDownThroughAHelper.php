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

    private ?Process $third = null;

    /** Every helper it calls counts, not only the first. */
    protected function tearDown(): void
    {
        $this->forget();
        $this->stopChild();
        parent::tearDown();
    }

    /** Its own stop counts as well as its helper's. */
    #[After]
    public function afterEach(): void
    {
        $this->third?->stop(0);
        static::stopOther();
    }

    public function startsTheThird(): void
    {
        $this->third = new Process(['sleep', '60']);
        $this->third->start();
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

    private function forget(): void
    {
        $this->third = null;
    }

    /** Called as static::stopOther(), which on an instance method still runs with $this. */
    private function stopOther(): void
    {
        $this->other?->stop(0);
    }
}
