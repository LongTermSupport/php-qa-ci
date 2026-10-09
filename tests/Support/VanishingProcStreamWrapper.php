<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A process table in which every process has just exited: its stat file is
 * still listed, so is_file() sees it, but opening it fails, and its
 * own directory has gone. That is the moment between ProcessTree's check and
 * its read, held still so a test can reach it every time.
 *
 * Registered under SCHEME by a test; the method names are PHP's stream
 * wrapper protocol.
 *
 * @internal
 */
final class VanishingProcStreamWrapper
{
    public const string SCHEME = 'vanishingproc';

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

    /** @return array{mode: int, size: int}|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return str_ends_with($path, '/stat') ? ['mode' => 0o100644, 'size' => 0] : false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
