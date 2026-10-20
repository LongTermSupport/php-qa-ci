<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a phase of code-modifying tools again until a pass leaves every watched
 * file as it found it.
 *
 * Each tool reads what the tools before it wrote, never what the tools after it
 * write, so a later tool can create work for an earlier one: PHP CS Fixer's
 * `final_class` makes a class final, and only then do Rector's final-class rules
 * apply. One pass would leave that work for the read-only run, which then fails
 * on a tree a writable run has just fixed (#193).
 *
 * A failed pass ends the loop. A phase still changing files after MAX_PASSES has
 * tools that undo each other, or a chain longer than MAX_PASSES; the files are
 * named, and the read-only run reports what is left.
 *
 * @internal
 */
final readonly class RepeatUntilSettled
{
    public const int MAX_PASSES = 3;

    public function __construct(private OutputInterface $output)
    {
    }

    /**
     * @param Closure(): bool $pass     one run of the phase; false ends the loop
     * @param string          $root     the directory a relative path is resolved against
     * @param string          ...$paths the files and directories the phase may change
     */
    public function run(Closure $pass, string $root, string ...$paths): void
    {
        for ($number = 1;; ++$number) {
            $before = $this->fingerprint($root, ...$paths);
            if (!$pass()) {
                return;
            }

            $after   = $this->fingerprint($root, ...$paths);
            $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
            if ([] === $changed) {
                return;
            }

            if (self::MAX_PASSES === $number) {
                $this->output->writeln(\sprintf('WARNING: the phase still changed %d file(s) on pass %d of %d: its tools undo each other, or need more passes than that; a read-only run reports what is left:', \count($changed), $number, self::MAX_PASSES));
                foreach ($changed as $file) {
                    $this->output->writeln('  ' . $file);
                }

                return;
            }

            $this->output->writeln(\sprintf("%d file(s) changed on pass %d; running the phase again so each tool sees the others' changes (pass %d of at most %d)", \count($changed), $number, $number + 1, self::MAX_PASSES));
        }
    }

    /** @return array<array-key, string> each file under the paths => a hash of its content */
    private function fingerprint(string $root, string ...$paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
            if (is_file($absolute)) {
                $hashes[$absolute] = \Safe\hash_file('sha256', $absolute);

                continue;
            }

            if (!is_dir($absolute)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $hashes[$file->getPathname()] = \Safe\hash_file('sha256', $file->getPathname());
                }
            }
        }

        return $hashes;
    }
}
