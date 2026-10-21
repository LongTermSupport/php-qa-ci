<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\Helper;

/**
 * Pure decision: can the analysed project's Composer autoloader load a class from the
 * file that declares it?
 *
 * A class the project's PSR-4 or PSR-0 mapping claims, by namespace prefix, is looked
 * for at the one path each claiming prefix gives. If the declaring file is none of those
 * paths, and no `classmap` or `files` entry loads that file, the autoloader cannot find
 * the class: it exists only once something else has loaded its file.
 * {@see ForbidUnautoloadableClassReferenceRule} turns the answer into a PHPStan error.
 *
 * A class no prefix claims is not judged. The project never asked its autoloader to find
 * it, so it comes from somewhere else: a dependency, the runtime, or a stub file the
 * analyser scans, and where it is declared says nothing about whether it loads.
 *
 * Both sections are read, `autoload` and `autoload-dev`, because a test reaches either.
 * A path that resolves is compared by its real path; one that does not is compared as
 * written, with `./` and doubled separators folded away.
 */
final readonly class AutoloadPathDetector
{
    private const string NAMESPACE_SEPARATOR = '\\';

    private const string PATH_SEPARATOR = '/';

    private const string PHP_EXTENSION = '.php';

    /** @var array<int|string, mixed> */
    private array $composerJson;

    /**
     * @param array<int|string, mixed>|null $composerJsonOverride decoded composer.json for
     *                                                            tests; null reads the project's own
     */
    public function __construct(private string $projectRoot, ?array $composerJsonOverride = null)
    {
        $this->composerJson = $composerJsonOverride ?? $this->readComposerJson();
    }

    /**
     * The path the autoloader looks for the class at, from the most specific prefix that
     * claims it, when the class cannot be autoloaded from the file that declares it; null
     * when it can, or when no prefix claims it.
     */
    public function expectedPathWhenUnloadable(string $className, string $fileName): ?string
    {
        $candidates = $this->claimedPaths($className);
        if ([] === $candidates) {
            return null;
        }

        $declaringFile = $this->resolve($fileName);
        foreach ($candidates as $candidate) {
            if ($this->resolve($candidate) === $declaringFile) {
                return null;
            }
        }

        if ($this->isLoadedWhatever($declaringFile)) {
            return null;
        }

        return $this->resolve($candidates[0]);
    }

    /**
     * The path relative to the project root, for a message; a path outside the root as given.
     */
    public function relativeToRoot(string $path): string
    {
        $root     = $this->resolve($this->projectRoot) . self::PATH_SEPARATOR;
        $resolved = $this->resolve($path);

        return str_starts_with($resolved, $root) ? substr($resolved, \strlen($root)) : $resolved;
    }

    /**
     * Every path a PSR-4 or PSR-0 prefix claiming the class gives, the longest prefix first.
     *
     * @return list<string>
     */
    private function claimedPaths(string $className): array
    {
        $byPrefixLength = [];
        foreach ($this->sections() as $section) {
            foreach ($this->prefixes($section, 'psr-4') as $prefix => $directories) {
                if (!str_starts_with($className, $prefix)) {
                    continue;
                }

                $relative = strtr(substr($className, \strlen($prefix)), self::NAMESPACE_SEPARATOR, self::PATH_SEPARATOR);
                foreach ($directories as $directory) {
                    $byPrefixLength[\strlen($prefix)][] = $this->absolute($directory) . self::PATH_SEPARATOR . $relative . self::PHP_EXTENSION;
                }
            }

            foreach ($this->prefixes($section, 'psr-0') as $prefix => $directories) {
                if (!str_starts_with($className, $prefix)) {
                    continue;
                }

                foreach ($directories as $directory) {
                    $byPrefixLength[\strlen($prefix)][] = $this->absolute($directory) . self::PATH_SEPARATOR . $this->psr0Path($className);
                }
            }
        }

        krsort($byPrefixLength);

        return array_merge(...array_values($byPrefixLength));
    }

    /**
     * PSR-0: the namespace becomes directories, and so does every underscore of the short name.
     */
    private function psr0Path(string $className): string
    {
        $lastSeparator = strrpos($className, self::NAMESPACE_SEPARATOR);
        if (false === $lastSeparator) {
            return strtr($className, '_', self::PATH_SEPARATOR) . self::PHP_EXTENSION;
        }

        $namespace = substr($className, 0, $lastSeparator);
        $shortName = substr($className, $lastSeparator + 1);

        return strtr($namespace, self::NAMESPACE_SEPARATOR, self::PATH_SEPARATOR)
            . self::PATH_SEPARATOR
            . strtr($shortName, '_', self::PATH_SEPARATOR)
            . self::PHP_EXTENSION;
    }

    /**
     * A `classmap` entry loads every class in the files under it, and a `files` entry is
     * loaded on every request: either way, the file's name decides nothing.
     */
    private function isLoadedWhatever(string $declaringFile): bool
    {
        foreach ($this->sections() as $section) {
            foreach ($this->entries($section, 'classmap') as $entry) {
                $covered = $this->resolve($this->absolute($entry));
                if ($covered === $declaringFile || str_starts_with($declaringFile, $covered . self::PATH_SEPARATOR)) {
                    return true;
                }
            }

            foreach ($this->entries($section, 'files') as $entry) {
                if ($this->resolve($this->absolute($entry)) === $declaringFile) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<int|string, mixed>>
     */
    private function sections(): array
    {
        $sections = [];
        foreach (['autoload', 'autoload-dev'] as $name) {
            $section = $this->composerJson[$name] ?? null;
            if (\is_array($section)) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Composer accepts one directory or a list of them for a prefix.
     *
     * @param array<int|string, mixed> $section
     *
     * @return array<string, list<string>>
     */
    private function prefixes(array $section, string $key): array
    {
        $mapping = $section[$key] ?? null;
        if (!\is_array($mapping)) {
            return [];
        }

        $prefixes = [];
        foreach ($mapping as $prefix => $directories) {
            if (!\is_string($prefix)) {
                continue;
            }

            $prefixes[$prefix] = $this->strings(\is_array($directories) ? $directories : [$directories]);
        }

        return $prefixes;
    }

    /**
     * @param array<int|string, mixed> $section
     *
     * @return list<string>
     */
    private function entries(array $section, string $key): array
    {
        $entries = $section[$key] ?? null;

        return \is_array($entries) ? $this->strings($entries) : [];
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, \is_string(...)));
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->projectRoot, self::PATH_SEPARATOR) . self::PATH_SEPARATOR . $relative;
    }

    private function resolve(string $path): string
    {
        if (!str_contains($path, '://') && file_exists($path)) {
            return \Safe\realpath($path);
        }

        $segments = [];
        foreach (explode(self::PATH_SEPARATOR, $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            $segments[] = $segment;
        }

        return self::PATH_SEPARATOR . implode(self::PATH_SEPARATOR, $segments);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readComposerJson(): array
    {
        $path = rtrim($this->projectRoot, self::PATH_SEPARATOR) . self::PATH_SEPARATOR . 'composer.json';
        if (!is_file($path)) {
            return [];
        }

        return Helper::getComposerJsonDecoded($path);
    }
}
