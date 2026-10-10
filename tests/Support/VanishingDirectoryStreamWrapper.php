<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The real filesystem, under SCHEME://<absolute path>, except for one
 * directory named by vanishOnOpen(): it is a directory to is_dir(), and is
 * removed, with the files it holds, at the moment something opens it, so the
 * open fails. That is a directory deleted by another process between a
 * caller's check and its read, held still so a test can reach it every time.
 *
 * A directory named by refuseOpen() instead fails to open and stays where it
 * is: the read fails for a reason other than the directory having gone.
 *
 * Each is spent by the first open it fails. The method names are PHP's stream
 * wrapper protocol. PHP's stream layer is what calls them, so the class is
 * tagged as API: an entry point to the dead-code detector, as a Composer
 * plugin is.
 *
 * @api
 */
final class VanishingDirectoryStreamWrapper
{
    public const string SCHEME = 'vanishingdir';

    /** @var resource|null set by PHP for every wrapper instance */
    public $context;

    private static ?string $vanishes = null;

    private static ?string $refused = null;

    /** @var resource|null */
    private $handle;

    public static function vanishOnOpen(string $directory): void
    {
        self::$vanishes = $directory;
    }

    public static function refuseOpen(string $directory): void
    {
        self::$refused = $directory;
    }

    public static function reset(): void
    {
        self::$vanishes = null;
        self::$refused  = null;
    }

    /** @return array<int|string, mixed>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = $this->real($path);
        if (!file_exists($real) && !is_link($real)) {
            return false;
        }

        return 0 !== ($flags & \STREAM_URL_STAT_LINK) ? \Safe\lstat($real) : stat($real);
    }

    public function dir_opendir(string $path): bool
    {
        $real = $this->real($path);
        if ($real === self::$refused) {
            self::$refused = null;

            return false;
        }

        if ($real === self::$vanishes) {
            self::$vanishes = null;
            foreach (\Safe\scandir($real) as $entry) {
                if (\is_string($entry) && is_file($real . '/' . $entry)) {
                    \Safe\unlink($real . '/' . $entry);
                }
            }

            \Safe\rmdir($real);

            return false;
        }

        $this->handle = \Safe\opendir($real);

        return true;
    }

    public function dir_readdir(): false|string
    {
        return null === $this->handle ? false : readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        if (null !== $this->handle) {
            rewinddir($this->handle);
        }

        return true;
    }

    public function dir_closedir(): bool
    {
        if (null !== $this->handle) {
            closedir($this->handle);
            $this->handle = null;
        }

        return true;
    }

    private function real(string $path): string
    {
        return substr($path, \strlen(self::SCHEME . '://'));
    }
}
