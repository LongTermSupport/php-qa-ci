<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The tearDown() and the #[After] method the trait provides stop both children. */
final class UsesTheStoppingTrait extends TestCase
{
    use StopsTheChildAfterEachTest;

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
}
