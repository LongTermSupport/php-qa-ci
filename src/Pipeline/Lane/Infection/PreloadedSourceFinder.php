<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use InvalidArgumentException;

/**
 * Which of the files already loaded when PHPUnit's bootstrap starts are files
 * Infection mutates (#155). Infection swaps a file for its mutant by
 * intercepting the include that loads it, and turns the interceptor on in its
 * own bootstrap; PHPUnit loads Composer's autoloader, and with it every
 * `files` entry, before any bootstrap. A source file loaded by then keeps its
 * original code in every mutant run, so none of its mutants can be killed.
 *
 * What Infection mutates is a file its source collector takes
 * (InfectionSourceScope) and, in a diff run, one of the files the lane passes
 * it. Paths are compared by their real path, as PHP records an included file
 * under it.
 *
 * @internal
 */
final readonly class PreloadedSourceFinder
{
    public function __construct(private InfectionSourceScope $sourceScope = new InfectionSourceScope())
    {
    }

    /**
     * @param list<string> $sourceDirectories absolute, as the lane's derived infection.json states them
     * @param list<string> $excludes          infection.json's `source.excludes`
     * @param list<string> $loaded            the files included once the autoloader has loaded
     * @param string       ...$scope          the files a diff run passes Infection; none for a full run
     *
     * @return list<string> the loaded files Infection mutates, each once, in the order loaded
     *
     * @throws InvalidArgumentException when an exclude Finder takes for a regex does not compile
     */
    public function find(array $sourceDirectories, array $excludes, array $loaded, string ...$scope): array
    {
        $inScope = array_map(static fn (string $path): string => file_exists($path) ? \Safe\realpath($path) : $path, $scope);
        $found   = [];
        foreach (array_unique($loaded) as $file) {
            if (([] === $inScope || \in_array($file, $inScope, true)) && null === $this->sourceScope->outside($file, $sourceDirectories, ...$excludes)) {
                $found[] = $file;
            }
        }

        return $found;
    }
}
