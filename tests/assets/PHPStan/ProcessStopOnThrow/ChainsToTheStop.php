<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** Overrides tearDown and calls the base class's, which stops the child. */
final class ChainsToTheStop extends AbstractStoppingTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }
}
