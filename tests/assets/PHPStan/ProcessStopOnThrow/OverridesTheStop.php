<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** Overrides tearDown without calling the base class's, so nothing stops the child. */
final class OverridesTheStop extends AbstractStoppingTestCase
{
    protected function tearDown(): void
    {
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }
}
