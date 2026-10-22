<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * A stream wrapper whose paths report as readable but cannot be opened, the way a file does when
 * the access check passes and the open then fails (a restricted procfs mount).
 *
 * Registered under SCHEME by a test; the method names are PHP's stream wrapper protocol. PHP's
 * stream layer is what calls them, so the class is tagged as API: an entry point to the
 * dead-code detector, as VanishingProcStreamWrapper is.
 *
 * @api
 */
final class UnopenableStreamWrapper
{
    public const string SCHEME = 'phpqa-unopenable';

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

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
