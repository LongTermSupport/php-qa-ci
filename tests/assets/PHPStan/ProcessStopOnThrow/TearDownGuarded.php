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

    #[After]
    public function stopTheOther(): void
    {
        $this->other?->stop(0);
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
}
