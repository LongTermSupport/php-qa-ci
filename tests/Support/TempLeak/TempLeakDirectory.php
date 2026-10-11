<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

use ErrorException;
use FilesystemIterator;
use RuntimeException;
use Safe\Exceptions\PosixException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * The temp directory of this test process: created and made the process's
 * TMPDIR once, before anything resolves sys_get_temp_dir().
 *
 * PHP resolves the temp directory once per process and keeps it, so the
 * redirect must come before the first resolution. A PHPUnit extension is too
 * late under Infection: a mutant run's bootstrap is Infection's own file,
 * which reads a compressed entry out of infection.phar, and PHP decompresses
 * it through a temporary file in the temp directory TMPDIR names at that
 * moment. So claim() is called from claim-before-bootstrap.php, a Composer
 * autoload-dev file, which PHPUnit's runner loads before its configuration
 * and bootstrap; TempLeakExtension adopts the directory when it starts. A
 * process whose extension never starts (`phpunit --version`, a run with
 * another configuration) removes the directory when it exits, if it is empty.
 */
final class TempLeakDirectory
{
    /** What every directory claim() creates is named after. */
    private const string PREFIX = 'php-qa-ci-tests-';

    /** A directory claim() creates: the owner's pid, then four random bytes in hex. */
    private const string PATTERN = '/^php-qa-ci-tests-(\d+)-[0-9a-f]{8}$/';

    private static ?string $claimed = null;

    private static bool $adopted = false;

    /** This process's temp directory, created and made TMPDIR on the first call. */
    public static function claim(): string
    {
        if (null !== self::$claimed) {
            return self::$claimed;
        }

        $tmpdir    = getenv('TMPDIR');
        $shared    = rtrim(\is_string($tmpdir) && '' !== $tmpdir ? $tmpdir : '/tmp', '/');
        $directory = $shared . '/' . self::PREFIX . \Safe\getmypid() . '-' . bin2hex(random_bytes(4));
        \Safe\mkdir($directory, 0o700);
        self::removeAbandoned($shared, new SplFileInfo($directory)->getOwner());
        \Safe\putenv('TMPDIR=' . $directory);
        $_ENV['TMPDIR'] = $directory;
        self::$claimed  = $directory;
        register_shutdown_function(static function () use ($directory): void {
            if (!self::$adopted) {
                self::removeIfUnused($directory);
            }
        });

        return $directory;
    }

    /** claim(), by TempLeakExtension, which then empties and removes the directory itself. */
    public static function adopt(): string
    {
        self::$adopted = true;

        return self::claim();
    }

    /**
     * Removes the directory of every test process under $shared that died
     * without removing it: Infection kills a mutant run that times out with
     * SIGKILL, and no shutdown function runs then. Only a real directory owned
     * by $uid (the owner of the directory this process has just created),
     * named exactly as claim() names one, whose pid is not running, is
     * removed; a symlink is never followed. A reused pid, or a killed process
     * not yet reaped, only keeps a stale directory until a later claim.
     */
    private static function removeAbandoned(string $shared, int $uid): void
    {
        foreach (self::entriesOf($shared) ?? [] as $name => $path) {
            $pid = self::ownerPid($name);
            if (null === $pid
                || is_link($path)
                || !is_dir($path)
                || self::isRunning($pid)
                || !self::ownedBy($path, $uid)) {
                continue;
            }

            self::removeTree($path);
        }
    }

    /**
     * Whether $pid names a process this user could signal, asked with signal
     * 0, which delivers nothing. No such process (ESRCH) is dead; one owned by
     * another user (EPERM) holds a reused pid, so the process of this user that
     * named the directory is dead too. claim() runs from a Composer files entry,
     * before Infection's bootstrap, so it must load nothing from src/: a source
     * file loaded there is never swapped for a mutant (#155). That is why this
     * does not use LTS\PHPQA\Pipeline\Process\ProcessTree.
     */
    private static function isRunning(int $pid): bool
    {
        try {
            \Safe\posix_kill($pid, 0);
        } catch (PosixException $posixException) {
            if (!\in_array(posix_get_last_error(), [\PCNTL_ESRCH, \PCNTL_EPERM], true)) {
                throw $posixException;
            }

            return false;
        }

        return true;
    }

    /** The pid in a name claim() gives a directory, or null for any other name. */
    private static function ownerPid(string $name): ?int
    {
        \Safe\preg_match(self::PATTERN, $name, $match);

        return isset($match[1]) ? (int)$match[1] : null;
    }

    private static function ownedBy(string $path, int $uid): bool
    {
        try {
            return new SplFileInfo($path)->getOwner() === $uid;
        } catch (RuntimeException $runtimeException) {
            if (self::stillThere($path)) {
                throw $runtimeException;
            }

            // Another claim removed it first.
            return false;
        }
    }

    /**
     * Two processes may remove the same directory at once, so whatever has
     * already gone when it is reached was removed by the other one, which is
     * the outcome wanted. A failure on a path still there is real.
     */
    private static function removeTree(string $directory): void
    {
        foreach (self::entriesOf($directory) ?? [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);

                continue;
            }

            self::unlessGone($path, \Safe\unlink(...));
        }

        self::unlessGone($directory, \Safe\rmdir(...));
    }

    /**
     * FilesystemIterator turns a failed open into an exception without the PHP
     * warning scandir() emits first, as ProjectTreeLedger reads the project tree.
     *
     * @return array<string, string>|null paths by name; null when the directory has gone
     */
    private static function entriesOf(string $directory): ?array
    {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $unexpectedValueException) {
            if (self::stillThere($directory)) {
                throw $unexpectedValueException;
            }

            return null;
        }

        /** @var array<string, string> */
        return iterator_to_array($iterator);
    }

    /** @param callable(string): void $remove */
    private static function unlessGone(string $path, callable $remove): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            $remove($path);
        } catch (ErrorException $errorException) {
            if (self::stillThere($path)) {
                throw $errorException;
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * PHP caches the last stat() of a path, so a path the caller has just
     * tested would still look present after another process removed it.
     */
    private static function stillThere(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    /**
     * Removes $directory when all it holds is files this process still has
     * open (the temporary copies PHP's phar extension decompresses entries
     * into, which go when the process exits). Anything else in it was left
     * there, so the directory is left too, for someone to see.
     */
    private static function removeIfUnused(string $directory): void
    {
        $entries = [];
        foreach (\Safe\scandir($directory) as $entry) {
            if (\is_string($entry) && '.' !== $entry && '..' !== $entry) {
                $entries[] = $directory . '/' . $entry;
            }
        }

        if ([] !== array_diff($entries, TempLeakSubscriber::openFiles())) {
            return;
        }

        foreach ($entries as $entry) {
            \Safe\unlink($entry);
        }

        \Safe\rmdir($directory);
    }
}
