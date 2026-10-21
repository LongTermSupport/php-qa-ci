<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A stream wrapper whose paths report as readable but cannot be opened, the way a file does when
 * the access check passes and the open then fails (a restricted procfs mount).
 *
 * @internal
 */
final class UnopenableStreamWrapper
{
    public const string SCHEME = 'phpqa-unopenable';

    /** @var resource|null set by PHP for the stream context; the wrapper does not use it */
    public $context;

    public static function register(): void
    {
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100444, 'uid' => \Safe\getmyuid(), 'gid' => \Safe\getmygid(), 'size' => 0];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
