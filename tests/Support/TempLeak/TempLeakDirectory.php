<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

use Closure;
use FilesystemIterator;
use RuntimeException;
use Safe\Exceptions\DirException;
use Safe\Exceptions\FilesystemException;
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

    /** The directory claim() made this process's temp directory; null until the first claim. */
    private static ?string $claimed = null;

    /** Whether TempLeakExtension took the directory over, so the shutdown function leaves it. */
    private static bool $adopted = false;

    /** What reapInterleaved() runs at each step of the walk; null everywhere else. */
    private static ?Closure $interleave = null;

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
     * What a claim does to the directories of dead test processes under $shared, with
     * $interleave run at each step of the walk where another process could change the tree
     * under it, given the step ('enter' or 'climb') and the name it is about to act on. For
     * TempLeakDirectoryTest, which makes that change at a known moment.
     *
     * @param Closure(string, string): void $interleave
     */
    public static function reapInterleaved(string $shared, int $uid, Closure $interleave): void
    {
        self::$interleave = $interleave;

        try {
            self::removeAbandoned($shared, $uid);
        } finally {
            self::$interleave = null;
        }
    }

    /**
     * Removes the directory of every test process under $shared that died
     * without removing it: Infection kills a mutant run that times out with
     * SIGKILL, and no shutdown function runs then. Only a real directory owned
     * by $uid (the owner of the directory this process has just created),
     * named exactly as claim() names one, whose pid is not running, is
     * removed; a symlink is never followed. A reused pid, or a killed process
     * not yet reaped, only keeps a stale directory until a later claim.
     * Without /proc this process does not look alive to itself, and then
     * nothing can be judged dead, so nothing goes.
     *
     * A directory that still cannot be removed is named in a WARNING and left,
     * and the run goes on: this runs before every PHPUnit bootstrap, so a throw
     * here would kill every later test run until someone removed it by hand.
     * The warning goes to stdout, as stderr stops Infection's initial test run.
     */
    private static function removeAbandoned(string $shared, int $uid): void
    {
        if (!self::isRunning(\Safe\getmypid())) {
            return;
        }

        foreach (self::namesIn($shared) ?? [] as $name) {
            $path = $shared . '/' . $name;
            $pid  = self::ownerPid($name);
            if (null === $pid
                || is_link($path)
                || !is_dir($path)
                || self::isRunning($pid)
                || !self::ownedBy($path, $uid)) {
                continue;
            }

            try {
                self::removeTree($path);
            } catch (DirException|FilesystemException|UnexpectedValueException $exception) {
                echo 'WARNING: could not remove ', $path, ', left by test process ', $pid, ' which died; the run goes on. Remove it by hand (rm -rf). ', $exception->getMessage(), \PHP_EOL;
            }
        }
    }

    /**
     * Whether the kernel still lists $pid in /proc. claim() runs from a
     * Composer files entry, before Infection's bootstrap, so it must load
     * nothing from src/: a source file loaded there is never swapped for a
     * mutant (#155). That is why this does not use
     * LTS\PHPQA\Pipeline\Process\ProcessTree.
     */
    private static function isRunning(int $pid): bool
    {
        return is_dir('/proc/' . $pid);
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
     *
     * The walk goes one relative step at a time from inside the tree, as
     * UnremovableTree does, so a tree nested past PATH_MAX, which a killed
     * test can leave, is removed too.
     */
    private static function removeTree(string $directory): void
    {
        $cwd = \Safe\getcwd();
        try {
            if (self::enter($directory)) {
                self::emptyCurrentDirectory();
            }
        } finally {
            \Safe\chdir($cwd);
        }

        self::unlessGone($directory, \Safe\rmdir(...));
    }

    /** Removes everything in the working directory, by names relative to it. */
    private static function emptyCurrentDirectory(): void
    {
        foreach (self::namesIn('.') ?? [] as $name) {
            if (is_dir($name) && !is_link($name)) {
                $here = \Safe\lstat('.');
                if (self::enter($name)) {
                    try {
                        self::emptyCurrentDirectory();
                        self::interleave('climb', $name);
                    } finally {
                        self::climbBackTo($here, $name);
                    }
                }

                self::unlessGone($name, \Safe\rmdir(...));

                continue;
            }

            self::unlessGone($name, \Safe\unlink(...));
        }
    }

    /**
     * Makes $directory the working directory; false when it has already gone.
     * The walk returns by '..', so landing anywhere but in the directory the
     * caller listed (a symlink swapped in for it) stops the walk before it
     * removes anything there.
     */
    private static function enter(string $directory): bool
    {
        $listed = [];
        if (!self::unlessGone($directory, static function (string $path) use (&$listed): void {
            $listed = \Safe\lstat($path);
        })) {
            return false;
        }

        self::interleave('enter', $directory);
        if (!self::unlessGone($directory, \Safe\chdir(...))) {
            return false;
        }

        $entered = \Safe\lstat('.');
        if ($entered['dev'] !== $listed['dev'] || $entered['ino'] !== $listed['ino']) {
            throw new FilesystemException($directory . ' was replaced while it was being removed');
        }

        return true;
    }

    /**
     * Returns from $name by '..', which leads to wherever $name is now: a directory moved while
     * the walk was inside it would leave the walk removing names in the directory it was moved
     * to. So landing anywhere but in $here, the directory $name was listed in, stops the walk.
     *
     * @param array<array-key, mixed> $here lstat() of the directory the walk climbs back to
     */
    private static function climbBackTo(array $here, string $name): void
    {
        \Safe\chdir('..');
        $landed = \Safe\lstat('.');
        if ($landed['dev'] !== $here['dev'] || $landed['ino'] !== $here['ino']) {
            throw new FilesystemException($name . ' was moved while it was being removed');
        }
    }

    private static function interleave(string $step, string $name): void
    {
        if (self::$interleave instanceof Closure) {
            (self::$interleave)($step, $name);
        }
    }

    /**
     * FilesystemIterator turns a failed open into an exception without the PHP
     * warning scandir() emits first, as ProjectTreeLedger reads the project tree.
     * The names are the iterator's values, never array keys: PHP makes a key
     * such as '12' (a git object directory) an int.
     *
     * @return list<string>|null null when the directory has gone
     */
    private static function namesIn(string $directory): ?array
    {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $unexpectedValueException) {
            if (self::stillThere($directory)) {
                throw $unexpectedValueException;
            }

            return null;
        }

        $names = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo) {
                $names[] = $entry->getFilename();
            }
        }

        return $names;
    }

    /**
     * Applies $operation to $path, with its PHP warning raised as the exception instead.
     *
     * @param callable(string): void $operation
     *
     * @return bool false when $path had already gone
     */
    private static function unlessGone(string $path, callable $operation): bool
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new FilesystemException($message, 0, $severity);
        });
        try {
            $operation($path);
        } catch (FilesystemException $filesystemException) {
            if (self::stillThere($path)) {
                throw $filesystemException;
            }

            return false;
        } finally {
            restore_error_handler();
        }

        return true;
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
