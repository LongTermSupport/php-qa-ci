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
 */

use Arkitect\ClassSet;

return static function (string $srcDir): ClassSet {
    $lines = static fn (string $variable): array => array_values(array_filter(
        array_map('trim', explode("\n", (string)getenv($variable))),
        static fn (string $line): bool => '' !== $line,
    ));

    // Finder keeps a path's `..` segments and symlinks in every pathname it
    // yields, so both sides of the ignored-path comparison are made canonical.
    $canonical = static function (string $path): string {
        $real = realpath($path);

        return false === $real ? rtrim($path, '/') : $real;
    };

    $classSet = ClassSet::fromDir($canonical($srcDir))->excludePath('Generated');
    foreach ($lines('PHPQACI_ARKITECT_EXCLUDE_PATHS') as $glob) {
        $classSet = $classSet->excludePath($glob);
    }

    $ignored = array_map($canonical, $lines('PHPQACI_ARKITECT_IGNORED_PATHS'));
    if ([] === $ignored) {
        return $classSet;
    }

    return new class($classSet, $ignored) extends ClassSet {
        /** @param list<string> $ignored canonical, without a trailing slash */
        public function __construct(private readonly ClassSet $inner, private readonly array $ignored)
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

                yield $key => $file;
            }
        }
    };
};
