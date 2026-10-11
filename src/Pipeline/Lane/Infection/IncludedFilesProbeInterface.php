<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * How the infection lane learns which files are already loaded when PHPUnit's
 * bootstrap starts, so that PreloadedSourceFinder can name the source files
 * Infection could never swap for a mutant. The shipped answer is
 * AutoloadIncludedFilesProbe, which asks a PHP child.
 *
 * @internal
 */
interface IncludedFilesProbeInterface
{
    /**
     * The files PHP has included once $autoload is loaded as PHPUnit's runner
     * loads it, with `PHPUNIT_COMPOSER_INSTALL` defined.
     *
     * @return list<string>|string the files, or why the probe could not say
     */
    public function includedFiles(ToolContext $context, string $autoload): array|string;
}
