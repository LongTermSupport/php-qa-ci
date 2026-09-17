<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\PackageType\AutoloadRootReader;

/**
 * Pure, framework-free decision: is this class dev-only code that a composer
 * `autoload` root nevertheless ships?
 *
 * A whole `Dev` segment — in the namespace below the root, or in the file's
 * directory path below the root — is the author declaring the code dev-only. An
 * `autoload` root declares the opposite, and `composer install --no-dev` obeys
 * the root. This class answers which shipped root is doing that, and nothing
 * else: {@see ForbidDevNamespaceInProductionSourceRule} turns the answer into a
 * PHPStan error.
 *
 * Extracted so the decision can be unit-tested without PHPStan's Scope or
 * RuleTestCase (PHPStan ships as a PHAR here, so neither is autoloadable as a
 * Composer class), and because it is string work over a composer layout that
 * touches no filesystem: every branch is reachable from a decoded composer.json
 * and a path.
 *
 * Namespace and path are BOTH read, because they fail differently. A file moved
 * before its namespace caught up is still installed by the root that contains
 * it; a class whose reflection resolves no file at all is still placed by its
 * namespace. The class's own short name and the file's basename are excluded
 * from both: a class NAMED `Dev` is not a `Dev` tree.
 */
final readonly class DevCodeInShippedRootDetector
{
    private const string DEV_SEGMENT = 'Dev';

    private const string NAMESPACE_SEPARATOR = '\\';

    private const string PATH_SEPARATOR = '/';

    public function __construct(
        private AutoloadRootReader $rootReader,
        private string $projectRoot,
    ) {
    }

    /**
     * The shipped PSR-4 prefix that installs this dev-only class, or null when
     * there is nothing to report.
     *
     * @param string|null $fileName the class's source file, or null when reflection
     *                              resolves none (PHP-internal / eval'd code)
     */
    public function shippedRootCarryingDevCode(string $className, ?string $fileName): ?string
    {
        if ($this->isDeclaredDevOnly($className, $fileName)) {
            return null;
        }

        foreach ($this->rootReader->productionRoots() as $prefix => $directories) {
            if (!$this->isUnderRoot($className, $fileName, $prefix, ...$directories)) {
                continue;
            }

            if ($this->carriesDevSegment($className, $fileName, $prefix, ...$directories)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Declared not-shipped, by namespace prefix or by directory: `composer install
     * --no-dev` drops it, so the contradiction this detector looks for cannot arise.
     */
    private function isDeclaredDevOnly(string $className, ?string $fileName): bool
    {
        return array_any($this->rootReader->devRoots(), fn (array $directories, string $prefix): bool => $this->isUnderRoot($className, $fileName, $prefix, ...$directories));
    }

    private function isUnderRoot(string $className, ?string $fileName, string $prefix, string ...$directories): bool
    {
        if (str_starts_with($className, $prefix)) {
            return true;
        }

        return array_any($directories, fn (string $directory): bool => null !== $this->relativeToRoot($fileName, $directory));
    }

    private function carriesDevSegment(string $className, ?string $fileName, string $prefix, string ...$directories): bool
    {
        if ($this->containsDevSegment(...$this->namespaceSegments($className, $prefix))) {
            return true;
        }

        foreach ($directories as $directory) {
            $relative = $this->relativeToRoot($fileName, $directory);
            if (null === $relative) {
                continue;
            }

            if ($this->containsDevSegment(...$this->pathSegments($relative))) {
                return true;
            }
        }

        return false;
    }

    private function containsDevSegment(string ...$segments): bool
    {
        return \in_array(self::DEV_SEGMENT, $segments, true);
    }

    /**
     * @return list<string> the namespace segments between the root prefix and the class's short name
     */
    private function namespaceSegments(string $className, string $prefix): array
    {
        if (!str_starts_with($className, $prefix)) {
            return [];
        }

        return $this->allButLast(
            ...explode(self::NAMESPACE_SEPARATOR, substr($className, \strlen($prefix))),
        );
    }

    /**
     * @return list<string> the directory segments of a root-relative file path
     */
    private function pathSegments(string $relativePath): array
    {
        return $this->allButLast(...explode(self::PATH_SEPARATOR, $relativePath));
    }

    /**
     * Drops the trailing name (the class's short name, or the file's basename) and
     * any empty segment a doubled or leading separator produced.
     *
     * @return list<string>
     */
    private function allButLast(string ...$segments): array
    {
        array_pop($segments);

        return array_values(array_filter($segments, static fn (string $segment): bool => '' !== $segment));
    }

    /**
     * The file's path relative to a root directory, or null when it is not under it.
     */
    private function relativeToRoot(?string $fileName, string $directory): ?string
    {
        if (null === $fileName) {
            return null;
        }

        $absoluteRoot = $this->absoluteRoot($directory);
        if (!str_starts_with($fileName, $absoluteRoot . self::PATH_SEPARATOR)) {
            return null;
        }

        return substr($fileName, \strlen($absoluteRoot) + 1);
    }

    private function absoluteRoot(string $directory): string
    {
        $root     = rtrim($this->projectRoot, self::PATH_SEPARATOR);
        $relative = trim($directory, self::PATH_SEPARATOR);

        if ('' === $relative || '.' === $relative) {
            return $root;
        }

        return $root . self::PATH_SEPARATOR . $relative;
    }
}
