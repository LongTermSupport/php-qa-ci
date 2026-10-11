<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

/** A static run() taking a callback: a call with no receiver at all. */
final class Launcher
{
    public static function run(callable $callback): void
    {
        $callback();
    }

    public static function start(): void
    {
    }
}
