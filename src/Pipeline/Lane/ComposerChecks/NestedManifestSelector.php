<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ComposerChecks;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\AnalysedPathsAudit;

/**
 * Which composer.json files below the project root are Composer projects of their own: every one
 * among the project's files, bar those under a path the project ignores (`withIgnoredPaths()`),
 * a path it declares unanalysed, or a default exclusion (`vendor/` and `var/`, as the
 * analysedPaths lane reads them). The root manifest is the lane's own and is not listed.
 *
 * A copy of the repository inside the tree, such as a git-ignored `.claude/worktrees/` checkout,
 * is not among the project's files at all, so it needs no exclusion here.
 *
 * @internal
 */
final readonly class NestedManifestSelector
{
    private const string MANIFEST = 'composer.json';

    private const string SEPARATOR = '/';

    /**
     * @param string ...$files project-relative, as git prints them
     *
     * @return list<string> project-relative, sorted, each once
     */
    public function select(QaConfigDto $config, string ...$files): array
    {
        $root     = rtrim($config->paths->projectRoot, self::SEPARATOR);
        $excluded = new IgnoredPaths(
            $root,
            ...$config->pathsToIgnore,
            ...array_map(strval(...), array_keys($config->unanalysedPaths)),
            ...array_keys(AnalysedPathsAudit::DEFAULT_UNANALYSED),
        );

        $selected = [];
        foreach ($files as $file) {
            if (self::MANIFEST === $file || self::MANIFEST !== basename($file) || $excluded->contains($root . self::SEPARATOR . $file)) {
                continue;
            }

            $selected[] = $file;
        }

        $selected = array_values(array_unique($selected));
        sort($selected, \SORT_STRING);

        return $selected;
    }
}
