<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Resolves a fully-qualified class name back to the file that declares it, by
 * looking in the class set rather than at the autoloader.
 *
 * PHPArkitect reports violations against classes and gives no path, so
 * something has to bridge the two. The autoloader is the wrong bridge: the
 * arch lane deliberately runs without needing a complete one, and asking a
 * loader to resolve a class would execute the project's code.
 *
 * The index is by SHORT name, which is what makes the common case a single
 * lookup. Where two files share a short name the declared namespace decides
 * between them, read straight out of the file. A class the set does not
 * contain resolves to null: attributing a violation to the wrong file would
 * be worse than reporting it without one.
 *
 * @internal
 */
final class ClassFileLocator
{
    /** @var array<string, list<string>>|null short name => candidate paths; null until the first lookup */
    private ?array $index = null;

    /** @var list<string> */
    private readonly array $roots;

    public function __construct(string ...$roots)
    {
        $this->roots = array_values($roots);
    }

    /** The absolute path declaring $fqcn, or null when the class set does not contain it. */
    public function locate(string $fqcn): ?string
    {
        $fqcn       = ltrim($fqcn, '\\');
        $separator  = strrpos($fqcn, '\\');
        $shortName  = false === $separator ? $fqcn : substr($fqcn, $separator + 1);
        $namespace  = false === $separator ? '' : substr($fqcn, 0, $separator);
        $candidates = $this->index()[$shortName] ?? [];

        foreach ($candidates as $candidate) {
            if ($this->namespaceOf($candidate) === $namespace) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private function index(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }

        $index = [];
        foreach ($this->roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $item */
            foreach ($walk as $item) {
                if ($item->isFile() && 'php' === $item->getExtension()) {
                    $index[$item->getBasename('.php')][] = $item->getPathname();
                }
            }
        }

        $this->index = $index;

        return $index;
    }

    /** The namespace a file declares, '' for the global namespace. */
    private function namespaceOf(string $path): string
    {
        // The capture is read through isset rather than trusted from the
        // match: the engine's out-parameter is typed nullable, and a file with
        // no namespace is the ordinary case here, not an exceptional one.
        $matches = [];
        if (1 !== \Safe\preg_match('/^\s*namespace\s+([^\s;{]+)\s*[;{]/m', \Safe\file_get_contents($path), $matches) || !isset($matches[1])) {
            return '';
        }

        return trim($matches[1], '\\');
    }
}
