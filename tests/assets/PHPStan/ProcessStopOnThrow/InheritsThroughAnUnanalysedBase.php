<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** An ancestor that was not analysed is passed over, and the base's tearDown still counts. */
final class InheritsThroughAnUnanalysedBase extends UnanalysedMiddle
{
    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }
}
