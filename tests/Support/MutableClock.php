<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lock\ClockInterface;

/**
 * A clock whose time a test sets.
 *
 * @internal
 */
final class MutableClock implements ClockInterface
{
    public function __construct(public int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
