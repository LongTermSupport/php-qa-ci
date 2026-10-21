<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Filesystem\DirectoryTree;
use LTS\PHPQA\Filesystem\TemporaryDirectory;
use Phar;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

/**
 * The dead-code detector as plain files, unpacked from its PHAR into the project's QA cache
 * once per PHAR content.
 *
 * PHPStan with Turbo active forks its workers, and Turbo guards reads across the fork for
 * phpstan.phar only. A second PHAR opened before the fork is read by every worker through one
 * shared archive descriptor, they race on its cursor, and each parses another's bytes. Plain
 * files have no shared cursor.
 *
 * @internal
 */
final readonly class DetectorUnpacker
{
    /** Under the QA cache directory: one subdirectory per PHAR content. */
    public const string DIR = 'dead-code-detector';

    /** The detector's Composer autoloader, for PHPStan's --autoload-file. */
    public const string AUTOLOAD = 'vendor/autoload.php';

    /** The detector's PHPStan config, for the wrapper neon's includes. */
    public const string RULES_NEON = 'vendor/shipmonk/dead-code-detector/rules.neon';

    /** @param OutputInterface $output where an entry the prune cannot remove is reported */
    public function __construct(private OutputInterface $output)
    {
    }

    /**
     * Everything else in the directory is removed: copies of earlier PHARs and staging left by a
     * killed run. Safe because the caller holds the run lock, so no other run is reading them.
     * Unpacking the current copy fails loudly; removing anything else is best-effort (prune(),
     * and the incomplete copy extract() moves aside), so the prune runs first: an entry this
     * run leaves is named once in this run, and again by the next run's prune.
     *
     * @return string the directory holding the unpacked detector
     */
    public function unpack(string $phar, string $cacheDir): string
    {
        $target = $cacheDir . '/' . self::DIR . '/' . substr(\Safe\hash_file('sha256', $phar), 0, 16);
        if (is_dir(\dirname($target))) {
            $this->prune($target);
        }

        if (!is_file($target . '/' . self::AUTOLOAD)) {
            $this->extract($phar, $target);
        }

        return $target;
    }

    /**
     * Unpacked beside the target and renamed into place, so a copy that exists is complete. An
     * incomplete one (a partial rm, a hand edit) is first renamed out of the way, never emptied
     * in place, so the target path is only ever absent or whole. When the fresh copy cannot be
     * renamed into place, that failure fails the run, and a moved-aside copy that cannot be
     * removed is named without saying the lane goes on.
     */
    private function extract(string $phar, string $target): void
    {
        $staging = TemporaryDirectory::besides($target, '.unpack');
        $discard = null;
        $inPlace = null;

        try {
            new Phar($phar)->extractTo($staging->path);
            if (file_exists($target) || is_link($target)) {
                $discard = TemporaryDirectory::besides($target, '.discard');
                \Safe\rename($target, $discard->path . '/incomplete');
            }

            \Safe\rename($staging->path, $target);
            $inPlace = $target;
        } finally {
            $staging->remove();
            if ($discard instanceof TemporaryDirectory) {
                $this->removeOrWarn($discard->path, 'moved aside as an incomplete copy', $inPlace);
            }
        }
    }

    /**
     * Housekeeping that never decides the lane's verdict: an entry that cannot be removed (one
     * a root container run left in a user's cache) is named in a warning and skipped, where a
     * throw would fail every run until someone removed it by hand.
     */
    private function prune(string $target): void
    {
        $directory = \dirname($target);
        foreach (\Safe\scandir($directory) as $entry) {
            if (!\is_string($entry) || \in_array($entry, ['.', '..', basename($target)], true)) {
                continue;
            }

            $this->removeOrWarn($directory . '/' . $entry, 'left in the dead-code detector cache by an earlier run', $target);
        }
    }

    /**
     * Removes $path, or names it in a warning saying how it came to be there ($origin) and goes
     * on; the warning says the lane goes on with $target only when there is one to go on with.
     */
    private function removeOrWarn(string $path, string $origin, ?string $target): void
    {
        try {
            $this->remove($path);
        } catch (FilesystemException|UnexpectedValueException $exception) {
            $this->output->writeln(\sprintf(
                'WARNING: could not remove %s, %s%s. Remove it by hand (another user may own it): %s',
                $path,
                $origin,
                null === $target ? '' : '; the lane goes on with ' . $target,
                $exception->getMessage(),
            ));
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            DirectoryTree::remove($path);
        } else {
            \Safe\unlink($path);
        }
    }
}
