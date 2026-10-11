<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The class's own tearDown() replaces the trait's, and stops nothing; the
 * helper it calls is another object's, not this class's stopChild(). The
 * trait's #[After] method still runs.
 */
final class OverridesTheTraitTearDown extends TestCase
{
    use StopsTheChildAfterEachTest;

    private ?Registry $registry = null;

    protected function tearDown(): void
    {
        $this->registry?->stopChild();
        $this->clearOutput();
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

    /** Would stop the child, but the tearDown calls another object's method of this name. */
    public function stopChild(): void
    {
        $this->child?->stop(0);
    }

    /** Called by the tearDown, and stops nothing. */
    private function clearOutput(): void
    {
        $this->other?->clearOutput();
    }
}
