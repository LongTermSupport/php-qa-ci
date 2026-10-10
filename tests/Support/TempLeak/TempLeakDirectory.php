<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

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
        $directory = $shared . '/php-qa-ci-tests-' . \Safe\getmypid() . '-' . bin2hex(random_bytes(4));
        \Safe\mkdir($directory, 0o700);
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
