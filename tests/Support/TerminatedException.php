<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use RuntimeException;

/**
 * Thrown by a test's terminate step in place of exiting, carrying the exit code.
 *
 * @internal
 */
final class TerminatedException extends RuntimeException
{
    public function __construct(public readonly int $exitCode)
    {
        parent::__construct('terminated with ' . $exitCode);
    }
}
