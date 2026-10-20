<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Filesystem\DirectoryTree;
use Throwable;

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
 * A directory named by vanishOnChange() opens and reads as usual, and is
 * removed, with everything in it, at the moment anything in it, or it, is
 * renamed, unlinked or removed, so that change fails as the real call does when
 * another process got there first.
 *
 * Each of those is spent by the first call it fails.
 *
 * Every directory with the name given to refuseOpenNamed() cannot be opened,
 * and every one with the name given to refuseRemovalNamed() cannot be removed
 * or moved, as one another user owns in a sticky directory, wherever it is,
 * until reset(). The next rename after throwOnRename() throws what it is given.
 *
 * The method names are PHP's stream
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

    private static ?string $vanishesOnChange = null;

    private static bool $vanishedOnChange = false;

    private static ?string $refusedName = null;

    private static ?string $unremovableName = null;

    private static ?Throwable $renameThrows = null;

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

    public static function vanishOnChange(string $directory): void
    {
        self::$vanishesOnChange = $directory;
        self::$vanishedOnChange = false;
    }

    /** Whether the directory named by vanishOnChange() has been removed under a change. */
    public static function hasVanishedOnChange(): bool
    {
        return self::$vanishedOnChange;
    }

    public static function refuseOpenNamed(string $name): void
    {
        self::$refusedName = $name;
    }

    public static function refuseRemovalNamed(string $name): void
    {
        self::$unremovableName = $name;
    }

    public static function throwOnRename(Throwable $throwable): void
    {
        self::$renameThrows = $throwable;
    }

    public static function reset(): void
    {
        self::$vanishes         = null;
        self::$refused          = null;
        self::$vanishesOnChange = null;
        self::$vanishedOnChange = false;
        self::$refusedName      = null;
        self::$unremovableName  = null;
        self::$renameThrows     = null;
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

        if (basename($real) === self::$refusedName) {
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

    public function mkdir(string $path, int $mode, int $options): bool
    {
        \Safe\mkdir($this->real($path), $mode, 0 !== ($options & \STREAM_MKDIR_RECURSIVE));

        return true;
    }

    public function rename(string $from, string $to): bool
    {
        $throwable = self::$renameThrows;
        if ($throwable instanceof Throwable) {
            self::$renameThrows = null;

            throw $throwable;
        }

        $real = $this->real($from);
        if (basename($real) === self::$unremovableName) {
            trigger_error(\sprintf('rename(%s,%s): Operation not permitted', $from, $to), \E_USER_WARNING);

            return false;
        }

        $this->vanishOnChangeTo($real);
        \Safe\rename($real, $this->real($to));

        return true;
    }

    public function rmdir(string $path): bool
    {
        $real = $this->real($path);
        if (basename($real) === self::$unremovableName) {
            trigger_error(\sprintf('rmdir(%s): Operation not permitted', $path), \E_USER_WARNING);

            return false;
        }

        $this->vanishOnChangeTo($real);
        \Safe\rmdir($real);

        return true;
    }

    public function unlink(string $path): bool
    {
        $real = $this->real($path);
        $this->vanishOnChangeTo($real);
        \Safe\unlink($real);

        return true;
    }

    /** Removes the directory named by vanishOnChange() when $real is it or is in it. */
    private function vanishOnChangeTo(string $real): void
    {
        $directory = self::$vanishesOnChange;
        if (null === $directory || ($real !== $directory && !str_starts_with($real, $directory . '/'))) {
            return;
        }

        self::$vanishesOnChange = null;
        self::$vanishedOnChange = true;
        DirectoryTree::remove($directory);
    }

    private function real(string $path): string
    {
        return substr($path, \strlen(self::SCHEME . '://'));
    }
}
