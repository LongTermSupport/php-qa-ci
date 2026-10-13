<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Filesystem\DirectoryTree;

/**
 * A throwaway directory under the system temp dir, removed recursively on
 * teardown.
 */
final readonly class TempDir
{
    private function __construct(public string $path)
    {
    }

    public static function create(string $prefix): self
    {
        return self::createUnder(sys_get_temp_dir(), $prefix);
    }

    /** The same, under another parent: a test that needs a second filesystem passes one. */
    public static function createUnder(string $parent, string $prefix): self
    {
        $path = $parent . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        \Safe\mkdir($path, 0o777, true);

        return new self($path);
    }

    /** Write a file (creating parent directories) and return its absolute path. */
    public function write(string $relative, string $contents): string
    {
        $absolute = $this->path . '/' . $relative;
        $dir      = \dirname($absolute);
        if (!is_dir($dir)) {
            \Safe\mkdir($dir, 0o777, true);
        }

        \Safe\file_put_contents($absolute, $contents);

        return $absolute;
    }

    public function read(string $relative): string
    {
        return \Safe\file_get_contents($this->path . '/' . $relative);
    }

    public function mkdir(string $relative): string
    {
        $absolute = $this->path . '/' . $relative;
        if (!is_dir($absolute)) {
            \Safe\mkdir($absolute, 0o777, true);
        }

        return $absolute;
    }

    /** @return list<string> file names (not paths) directly under $relative, sorted */
    public function files(string $relative = ''): array
    {
        $dir   = '' === $relative ? $this->path : $this->path . '/' . $relative;
        $names = [];
        foreach (\Safe\scandir($dir) as $entry) {
            if (\is_string($entry) && '.' !== $entry && '..' !== $entry && is_file($dir . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    public function remove(): void
    {
        DirectoryTree::remove($this->path);
    }
}
