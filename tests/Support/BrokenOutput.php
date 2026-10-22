<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A console whose terminal has gone, as after SIGHUP: every write fails.
 *
 * @internal
 */
final class BrokenOutput extends BufferedOutput
{
    protected function doWrite(string $message, bool $newline): void
    {
        throw new RuntimeException('Unable to write output.');
    }
}
