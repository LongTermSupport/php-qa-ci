<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHPUnit runs tearDown() and every #[After] method after a test that throws,
 * so a child held in a property they stop is stopped.
 */
final class TearDownGuarded extends TestCase
{
    private ?Process $child = null;

    private ?Process $other = null;

    protected function tearDown(): void
    {
        if (null !== $this->child && $this->child->isRunning()) {
            $this->child->stop(0);
        }
    }

    private ?Process $third = null;

    private ?Process $spare = null;

    #[After]
    public function stopTheOther(): void
    {
        $this->other?->stop(0);
    }

    /** Every #[After] method runs, and every stop in it counts, not only the first. */
    #[After]
    public function stopTheSpareAndTheThird(): void
    {
        $this->spare?->stop(0);
        $this->third?->stop(0);
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
        self::assertTrue($this->child->isRunning());
    }

    public function runsTheOtherWithACallback(): void
    {
        $this->other = new Process(['sleep', '1']);
        $this->other->run(static function (string $type, string $buffer): void {
            echo $buffer;
        });
    }

    /** The input is given here and the child run elsewhere; tearDown stops it either way. */
    public function feedsTheChild(): void
    {
        $this->child?->setInput($this->lines());
    }

    /** @return \Generator<int, string> */
    private function lines(): \Generator
    {
        yield "a\n";
    }
}
