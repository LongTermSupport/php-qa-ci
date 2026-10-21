<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DeadCode;

/**
 * The files a PHPStan run analyses, from its merged `paths`, `excludePaths` and `fileExtensions`,
 * judged as PHPStan judges them:
 *
 * - a listed file is analysed whatever its name (FileFinder);
 * - a listed directory is walked through links for files with a listed suffix, case-sensitively,
 *   leaving out dot files, dot directories and the VCS directories Symfony Finder ignores
 *   (DirectoryWalker);
 * - either is dropped when an exclusion matches the path PHPStan has for it, the listed path or
 *   the walked one, normalised: an existing file by name, an existing directory by prefix, a
 *   pattern by fnmatch() with no flags, so `*` crosses `/`; an exclusion naming nothing is ignored
 *   (FileExcluder).
 *
 * @internal
 */
final readonly class AnalysedFiles
{
    /** A pattern to PHPStan's FileExcluder: one of `*?[]`. */
    private const string PATTERN = '~[*?[\]]~';

    /** The directories Symfony Finder's ignoreVCS() leaves out that do not start with a dot. */
    private const array VCS_DIRECTORIES = ['_svn', 'CVS', '_darcs'];

    /** Joins path segments. */
    private const string SEPARATOR = '/';

    /** @var array<string, true> normalised paths of excluded files */
    private array $excludedFiles;

    /** @var list<string> normalised paths of excluded directories, each ending in `/` */
    private array $excludedDirectories;

    /** @var list<string> */
    private array $excludedPatterns;

    /**
     * @param list<string> $paths          absolute, files or directories
     * @param list<string> $excludePaths   absolute: files, directories or fnmatch() patterns
     * @param list<string> $fileExtensions without the dot
     */
    public function __construct(private array $paths, array $excludePaths, private array $fileExtensions)
    {
        $files       = [];
        $directories = [];
        $patterns    = [];
        foreach ($excludePaths as $exclude) {
            $normalised = $this->normalise($exclude) . (str_ends_with($exclude, self::SEPARATOR) ? self::SEPARATOR : '');
            if (1 === \Safe\preg_match(self::PATTERN, $normalised)) {
                $patterns[] = $normalised;
            } elseif (is_file($normalised)) {
                $files[$normalised] = true;
            } elseif (is_dir($normalised)) {
                $directories[] = rtrim($normalised, self::SEPARATOR) . self::SEPARATOR;
            }
        }

        $this->excludedFiles       = $files;
        $this->excludedDirectories = $directories;
        $this->excludedPatterns    = $patterns;
    }

    /** Whether the run analyses the file whose real path is $real. */
    public function analyses(string $real): bool
    {
        foreach ($this->paths as $path) {
            $spelled = $this->spelledFrom($path, $real);
            if (null !== $spelled && !$this->isExcluded($spelled)) {
                return true;
            }
        }

        return false;
    }

    /** The path PHPStan has for $real when it reaches it from the listed $path; null when it does not. */
    private function spelledFrom(string $path, string $real): ?string
    {
        if (is_file($path)) {
            return \Safe\realpath($path) === $real ? $this->normalise($path) : null;
        }

        if (!is_dir($path)) {
            return null;
        }

        $directory = \Safe\realpath($path);
        if (!str_starts_with($real, rtrim($directory, self::SEPARATOR) . self::SEPARATOR)) {
            return null;
        }

        $relative = substr($real, \strlen(rtrim($directory, self::SEPARATOR)) + 1);

        return $this->isWalked($relative) ? rtrim($this->normalise($path), self::SEPARATOR) . self::SEPARATOR . $relative : null;
    }

    /** A file the walk yields: a listed suffix, and no dot or VCS name on the way. */
    private function isWalked(string $relative): bool
    {
        $segments = explode(self::SEPARATOR, $relative);
        $name     = array_pop($segments);
        foreach ([...$segments, $name] as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        if ([] !== array_intersect($segments, self::VCS_DIRECTORIES)) {
            return false;
        }

        return array_any($this->fileExtensions, static fn (string $extension): bool => str_ends_with($name, '.' . $extension));
    }

    private function isExcluded(string $spelled): bool
    {
        return isset($this->excludedFiles[$spelled])
            || array_any($this->excludedDirectories, static fn (string $directory): bool => str_starts_with($spelled, $directory))
            || array_any($this->excludedPatterns, static fn (string $pattern): bool => fnmatch($pattern, $spelled));
    }

    /** `.` and `..` resolved and repeated separators collapsed, without following links, as PHPStan's FileHelper does. */
    private function normalise(string $path): string
    {
        $segments = [];
        foreach (explode(self::SEPARATOR, $path) as $segment) {
            if ('..' === $segment) {
                array_pop($segments);
            } elseif ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return self::SEPARATOR . implode(self::SEPARATOR, $segments);
    }
}
