<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The files a project holds, for a lane that has to look at the whole project rather than at the
 * checked paths: the analysedPaths lane accounts for every PHP file, the composerChecks lane finds
 * every composer.json. One listing serves both, so they never disagree about what the project is.
 *
 * @internal
 */
interface ProjectFilesInterface
{
    /**
     * @return list<string>|null project-relative, as git prints them; null when there is no answer
     */
    public function files(ToolContext $context): ?array;
}
