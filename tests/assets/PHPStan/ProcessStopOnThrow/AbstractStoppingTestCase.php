<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** A base test case whose tearDown stops the child its subclasses start. */
abstract class AbstractStoppingTestCase extends TestCase
{
    protected const string TEARDOWN = 'tearDown';

    protected ?Process $child = null;

    protected function tearDown(): void
    {
        $this->child?->stop(0);
    }
}
