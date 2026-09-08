<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lock;

/**
 * @internal
 */
interface ClockInterface
{
    /** Unix timestamp, seconds. */
    public function now(): int;
}
