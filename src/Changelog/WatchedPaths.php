<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

/**
 * The project-relative paths whose changes a consuming project can notice,
 * so a change to any of them needs a changelog entry. A path ending `/`
 * watches everything under that directory; any other path is an fnmatch
 * pattern (a plain file name matches itself) in which `*` crosses directory
 * separators, so `templates/*.yml` reaches nested directories.
 *
 * @api
 */
final readonly class WatchedPaths
{
    /** @var list<string> */
    private array $paths;

    public function __construct(string ...$paths)
    {
        $normalised = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }

            $path = ltrim($path, '/');
            if ('' !== $path) {
                $normalised[] = $path;
            }
        }

        $this->paths = $normalised;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> the files that fall under a watched path, in the order given */
    public function matching(string ...$files): array
    {
        return array_values(array_filter($files, $this->watches(...)));
    }

    private function watches(string $file): bool
    {
        foreach ($this->paths as $path) {
            if (str_ends_with($path, '/') ? str_starts_with($file, $path) : fnmatch($path, $file)) {
                return true;
            }
        }

        return false;
    }
}
