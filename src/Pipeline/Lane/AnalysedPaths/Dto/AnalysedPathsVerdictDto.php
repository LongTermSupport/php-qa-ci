<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\AnalysedPaths\Dto;

/**
 * What the analysed-paths audit found in one project's PHP.
 *
 * `$unclassified` is keyed by unit: a directory with a trailing slash, or a
 * file at the project root by its own name, because declaring the root would
 * declare the whole project. `$declaredInUse` counts the PHP each declaration
 * accounts for; `$stale` lists declarations that account for none, and
 * `$contradicted` maps a declaration lying inside an analysed path to that
 * path.
 *
 * @internal
 */
final readonly class AnalysedPathsVerdictDto
{
    /**
     * @param array<string, int>    $unclassified  unit => PHP files, in path order
     * @param array<string, int>    $declaredInUse declared path => PHP files it accounts for
     * @param list<string>          $stale         declared paths with no PHP file beneath them
     * @param array<string, string> $contradicted  declared path => the analysed path containing it
     */
    public function __construct(
        public int $phpFiles,
        public array $unclassified,
        public array $declaredInUse,
        public array $stale,
        public array $contradicted,
    ) {
    }

    public function passes(): bool
    {
        return [] === $this->unclassified && [] === $this->stale && [] === $this->contradicted;
    }
}
