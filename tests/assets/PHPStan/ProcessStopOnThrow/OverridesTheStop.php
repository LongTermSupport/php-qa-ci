<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/**
 * Overrides tearDown without calling the base class's, so nothing stops the
 * child: a parent method of another name, another class's tearDown() and a
 * parent constant named TEARDOWN are not the parent's tearDown().
 */
final class OverridesTheStop extends AbstractStoppingTestCase
{
    protected function tearDown(): void
    {
        parent::tearDownAfterClass();
        Cleaner::tearDown();
        self::assertSame('tearDown', parent::TEARDOWN);
    }

    public function startsTheChild(): void
    {
        $this->child = new Process(['sleep', '60']);
        $this->child->start();
        self::assertTrue($this->child->isRunning());
    }
}
