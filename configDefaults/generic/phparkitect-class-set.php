<?php

declare(strict_types=1);

/**
 * php-qa-ci PHPArkitect — the class set an entry config analyses.
 *
 * Returns a factory: give it the source dir, get the ClassSet to pass to
 * $config->add(). The pipeline exports this file's path as
 * PHPQACI_ARKITECT_CLASS_SET, so an entry config builds its class set with
 *
 *   $classSet = (require getenv('PHPQACI_ARKITECT_CLASS_SET'))($srcDir);
 *
 * The set leaves out three things:
 *
 * - `Generated`, the built-in convention for generated code, which cannot be
 *   renamed;
 * - each glob a project declares with ->withArkitectExcludedPaths(...) in
 *   qaConfig/qa.php (PHPQACI_ARKITECT_EXCLUDE_PATHS, newline-delimited), matched
 *   by arkitect's Glob::toRegex against the path relative to the source dir,
 *   anywhere in it;
 * - each path the project ignores with ->withIgnoredPaths(...)
 *   (PHPQACI_ARKITECT_IGNORED_PATHS, absolute, newline-delimited), anchored: an
 *   ignored src/Legacy drops src/Legacy/ and keeps src/Domain/Legacy/.
 *
 * Arkitect's own excludePath() cannot anchor, since it builds an unanchored
 * regex, so the ignored paths are taken out of the set's iterator instead.
 *
 * The iterator also drops each file whose real path cannot be resolved (a
 * symlink whose target does not exist), which arkitect would fail to read and
 * report as a violation without naming it. Each one is appended, by its
 * directory's real path, to the file PHPQACI_ARKITECT_UNRESOLVED_LIST names,
 * which the lane reads back and reports as skipped.
 */

use Arkitect\ClassSet;

return static function (string $srcDir): ClassSet {
    $lines = static fn (string $variable): array => array_values(array_filter(
        array_map('trim', explode("\n", (string)getenv($variable))),
        static fn (string $line): bool => '' !== $line,
    ));

    // Finder keeps a path's `..` segments in every pathname it yields, and
    // yields a symlinked directory's files under the link's own name. So both
    // sides of the ignored-path comparison fold `.` and `..` lexically, and
    // neither resolves a symlink: an ignored link is matched by its name. A
    // relative path is taken from the working directory first, since the
    // ignored paths are absolute.
    $canonical = static function (string $path): string {
        $cwd = getcwd();
        if (!str_starts_with($path, '/') && false !== $cwd) {
            $path = $cwd . '/' . $path;
        }

        $absolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment && [] !== $segments && '..' !== end($segments)) {
                array_pop($segments);

                continue;
            }

            if ('..' === $segment && $absolute) {
                continue;
            }

            $segments[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $segments);
    };

    $classSet = ClassSet::fromDir($canonical($srcDir))->excludePath('Generated');
    foreach ($lines('PHPQACI_ARKITECT_EXCLUDE_PATHS') as $glob) {
        $classSet = $classSet->excludePath($glob);
    }

    $ignored    = array_map($canonical, $lines('PHPQACI_ARKITECT_IGNORED_PATHS'));
    $unresolved = (string)getenv('PHPQACI_ARKITECT_UNRESOLVED_LIST');

    return new class($classSet, $ignored, $unresolved) extends ClassSet {
        /**
         * @param list<string> $ignored    lexically folded, without a trailing slash
         * @param string       $unresolved the list each dropped unresolvable file is appended to, or '' for none
         */
        public function __construct(private readonly ClassSet $inner, private readonly array $ignored, private readonly string $unresolved)
        {
        }

        public function excludePath(string $pattern): ClassSet
        {
            $this->inner->excludePath($pattern);

            return $this;
        }

        public function getDirsDescription(): string
        {
            return $this->inner->getDirsDescription();
        }

        public function getIterator(): Traversable
        {
            foreach ($this->inner as $key => $file) {
                $path = $file->getPathname();
                foreach ($this->ignored as $ignored) {
                    if ($path === $ignored || str_starts_with($path, $ignored . '/')) {
                        continue 2;
                    }
                }

                if (false === $file->getRealPath()) {
                    if ('' !== $this->unresolved) {
                        $dir = realpath($file->getPath());
                        file_put_contents($this->unresolved, (false === $dir ? $path : $dir . '/' . $file->getFilename()) . "\n", FILE_APPEND | LOCK_EX);
                    }

                    continue;
                }

                yield $key => $file;
            }
        }
    };
};
