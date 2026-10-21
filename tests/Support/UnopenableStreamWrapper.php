<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A stream wrapper whose paths report as readable but cannot be opened, the way a file does when
 * the access check passes and the open then fails (a restricted procfs mount).
 *
 * PHP sets the wrapper's `context` property on every instance it creates, so the class allows
 * dynamic properties rather than declare one nothing reads.
 *
 * @internal
 */
#[\AllowDynamicProperties]
final class UnopenableStreamWrapper
{
    public const string SCHEME = 'phpqa-unopenable';

    public static function register(): void
    {
        \Safe\stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        \Safe\stream_wrapper_unregister(self::SCHEME);
    }

    /** @return array<string, int> */
    public function url_stat(): array
    {
        return ['mode' => 0o100444, 'uid' => \Safe\getmyuid(), 'gid' => \Safe\getmygid(), 'size' => 0];
    }

    public function stream_open(): bool
    {
        return false;
    }
}
