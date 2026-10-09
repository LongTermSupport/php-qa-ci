<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The environment a test gives a PHP child process it asserts on.
 *
 * The pipeline runs PHPUnit with `XDEBUG_MODE=coverage`, and a child inherits that. On a host with
 * `opcache.jit` on, an Xdebug-loaded child prints "JIT is incompatible with third party
 * extensions" on stdout, which breaks every exact-output assertion. A child that is not measuring
 * coverage gets Xdebug off, from this one place. `-d xdebug.mode=off` is not enough: the
 * environment variable outranks the ini setting.
 */
final readonly class ChildEnvironment
{
    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $env the child's own variables, which may not name an Xdebug mode
     *
     * @return array<string, string>
     */
    public static function withoutXdebug(array $env = []): array
    {
        return [...$env, self::XDEBUG_MODE => 'off'];
    }
}
