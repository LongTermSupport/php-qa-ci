<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** Declares no tearDown, so the base class's runs and stops the child. */
final class InheritsTheStop extends AbstractStoppingTestCase
{
    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }
}
