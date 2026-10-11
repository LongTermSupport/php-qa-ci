<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

/** A static tearDown() on another class: calling it is not calling the parent's. */
final class Cleaner
{
    public static function tearDown(): void
    {
    }
}
