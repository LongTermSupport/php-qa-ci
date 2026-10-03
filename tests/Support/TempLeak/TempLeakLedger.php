<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Charges whatever is left in the run's temp directory to the test that just
 * finished, then empties the directory so the next test is judged on its own.
 */
final class TempLeakLedger
{
    /** @var list<string> */
    private array $leaks = [];

    /** @var list<string> */
    private readonly array $retained;

    /**
     * @param string ...$retained entry names a test harness keeps across tests on purpose, such
     *                            as PHPStan's container cache; removed by clear(), never charged
     */
    public function __construct(private readonly string $directory, string ...$retained)
    {
        $this->retained = array_values($retained);
    }

    /**
     * @param string ...$inUse absolute paths the process still holds open; such an entry is
     *                         in use (PHP's phar extension keeps a decompressed entry there
     *                         until the process exits), so it is neither charged nor swept
     */
    public function sweep(string $test, string ...$inUse): void
    {
        $left = array_values(array_filter(
            array_diff($this->entries(), $this->retained),
            fn (string $entry): bool => !\in_array($this->directory . '/' . $entry, $inUse, true),
        ));
        if ([] === $left) {
            return;
        }

        sort($left);
        $this->leaks[] = \sprintf('%s left %s', $test, implode(', ', $left));
        foreach ($left as $entry) {
            $this->remove($this->directory . '/' . $entry);
        }
    }

    /** Removes everything, retained entries included, once the run is over. */
    public function clear(): void
    {
        foreach ($this->entries() as $entry) {
            $this->remove($this->directory . '/' . $entry);
        }
    }

    /** @return list<string> one line per test that left something */
    public function leaks(): array
    {
        return $this->leaks;
    }

    /** @return list<string> the directory's entries, without . and .. */
    private function entries(): array
    {
        return array_values(array_filter(
            \Safe\scandir($this->directory),
            static fn (mixed $entry): bool => \is_string($entry) && '.' !== $entry && '..' !== $entry,
        ));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            \Safe\unlink($path);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                \Safe\rmdir($item->getPathname());
            } else {
                \Safe\unlink($item->getPathname());
            }
        }

        \Safe\rmdir($path);
    }
}
