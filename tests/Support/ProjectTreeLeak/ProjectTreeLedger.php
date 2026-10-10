<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\ProjectTreeLeak;

use ErrorException;
use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Charges what appeared in the project tree to the test that just finished,
 * and moves it out of the tree into a quarantine directory, where it is kept
 * for inspection rather than destroyed: a path that appears during a test is
 * as likely to be a developer's new file as a test's escaped write, and
 * neither can be told from the other here.
 *
 * The tree is read two levels deep: every entry of the project root, and
 * every entry of each directory there. A path made relative by a mutant lands
 * at the top level (`linux-gnu-x86_64/`, `php:`), or one level into a
 * directory that already exists (`src/…`, `bin/…`). Nothing deeper is read,
 * and no file's content is compared, so the probe stays a few dozen
 * directory reads per test. The unwatched entries are neither read nor
 * charged: the suite's own output (`var/`), Composer's (`vendor/`), git's,
 * and scratch space other processes write to while the suite runs.
 *
 * Only a path absent from the previous reading is ever moved. A path that
 * disappears is charged as deleted, once, and cannot be restored here.
 */
final class ProjectTreeLedger
{
    /** @var list<string> */
    private array $leaks = [];

    /** @var list<string> */
    private array $baseline;

    /** @var list<string> */
    private readonly array $unwatched;

    private int $quarantined = 0;

    /**
     * @param string $quarantine   where a charged path is moved, in a numbered directory per charge;
     *                             outside the root or under an unwatched entry, so it is never itself read
     * @param string ...$unwatched names of root entries that are neither read nor charged
     */
    public function __construct(private readonly string $root, private readonly string $quarantine, string ...$unwatched)
    {
        $this->unwatched = array_values($unwatched);
        if (str_starts_with($quarantine, $root . '/')) {
            $entry = explode('/', substr($quarantine, \strlen($root) + 1))[0];
            if (!\in_array($entry, $this->unwatched, true)) {
                throw new InvalidArgumentException(\sprintf('The quarantine %s must be outside %s or under an unwatched entry, not under the watched %s', $quarantine, $root, $entry));
            }
        }

        $this->baseline = $this->entries();
    }

    public function sweep(string $test): void
    {
        $current        = $this->entries();
        $written        = $this->outermost(array_diff($current, $this->baseline));
        $deleted        = $this->outermost(array_diff($this->baseline, $current));
        $this->baseline = array_values(array_intersect($this->baseline, $current));

        if ([] !== $written && !$this->charge($test, ...$written)) {
            // What could not be moved is still there; it is charged once, not again by every later test.
            $this->baseline = $this->entries();
        }

        if ([] !== $deleted) {
            $this->leaks[] = \sprintf('%s deleted %s from the project tree', $test, implode(', ', $deleted));
        }
    }

    /** @return list<string> one line per test that wrote into, or deleted from, the tree */
    public function leaks(): array
    {
        return $this->leaks;
    }

    /** @return bool whether every path was moved */
    private function charge(string $test, string ...$written): bool
    {
        $destination = $this->quarantine . '/' . ++$this->quarantined;
        $unmoved     = [];
        foreach ($written as $relative) {
            try {
                $this->move($this->root . '/' . $relative, $destination . '/' . $relative);
            } catch (ErrorException $exception) {
                $unmoved[] = \sprintf('; could not move %s: %s', $relative, $exception->getMessage());
            }
        }

        $this->leaks[] = \sprintf('%s wrote %s into the project tree (moved to %s)%s', $test, implode(', ', $written), $destination, implode('', $unmoved));

        return [] === $unmoved;
    }

    /**
     * A failure here is reported in the charge, not raised as a PHP warning against
     * whichever test happens to be running: another process (a parallel Infection
     * run in the same tree) may have moved the path first.
     *
     * @throws ErrorException
     */
    private function move(string $source, string $target): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            if (!is_dir(\dirname($target))) {
                \Safe\mkdir(\dirname($target), 0o700, true);
            }

            \Safe\rename($source, $target);
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<string> the root's watched entries, and the entries of each watched directory */
    private function entries(): array
    {
        $entries = [];
        foreach ($this->names($this->root) ?? throw new RuntimeException(\sprintf('The project root %s has gone', $this->root)) as $name) {
            if (\in_array($name, $this->unwatched, true)) {
                continue;
            }

            $path     = $this->root . '/' . $name;
            $children = is_dir($path) && !is_link($path) && is_readable($path) ? $this->names($path) : [];
            if (null === $children) {
                // Removed between is_dir() and the read: absent, so charged as deleted, once.
                continue;
            }

            $entries[] = $name;
            foreach ($children as $child) {
                $entries[] = $name . '/' . $child;
            }
        }

        return $entries;
    }

    /**
     * FilesystemIterator turns a failed open into an exception without the PHP
     * warning scandir() emits first, which would fail the test running at the time.
     *
     * @return list<string>|null null when the directory has gone
     */
    private function names(string $directory): ?array
    {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $unexpectedValueException) {
            // Another process can remove a directory between the caller's check and this
            // read, which is an answer; one that is still there is a real failure. PHP
            // keeps the caller's stat of the path, which that removal does not clear.
            clearstatcache(true, $directory);
            if (is_dir($directory)) {
                throw $unexpectedValueException;
            }

            return null;
        }

        return array_map(strval(...), array_keys(iterator_to_array($iterator)));
    }

    /**
     * The paths whose parent is not itself listed, sorted: a new directory is named once, not once per entry in it.
     *
     * @param array<array-key, string> $paths
     *
     * @return list<string>
     */
    private function outermost(array $paths): array
    {
        $outermost = array_values(array_filter(
            $paths,
            static fn (string $path): bool => !str_contains($path, '/') || !\in_array(\dirname($path), $paths, true),
        ));
        sort($outermost);

        return $outermost;
    }
}
