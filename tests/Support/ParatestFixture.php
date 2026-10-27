<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The real `paratest --help` and `paratest --version` output of an installed
 * ParaTest, captured verbatim (ANSI colour codes included) under
 * tests/assets/paratest, so a test judges the lane against what ParaTest prints.
 *
 * @internal
 */
final readonly class ParatestFixture
{
    public static function help(string $version): string
    {
        return \Safe\file_get_contents(self::path($version, 'help'));
    }

    public static function version(string $version): string
    {
        return \Safe\file_get_contents(self::path($version, 'version'));
    }

    private static function path(string $version, string $kind): string
    {
        return \dirname(__DIR__) . '/assets/paratest/' . $version . '.' . $kind . '.txt';
    }
}
