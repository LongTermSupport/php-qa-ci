<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * Runs bin/autoload-included-files, which defines `PHPUNIT_COMPOSER_INSTALL`,
 * requires the autoloader as PHPUnit's runner does, and prints
 * `get_included_files()` as JSON after MARKER. Composer runs every autoload
 * `files` entry at that point, so the list holds everything loaded before any
 * PHPUnit bootstrap, Infection's included.
 *
 * Only stdout after the last MARKER is read: a files entry may print, and a
 * warning on stderr is not part of the answer. A child that fails, prints no
 * MARKER (a files entry that exits stops the script before it prints) or
 * prints something other than a list of paths gives the reason instead.
 *
 * @internal
 */
final readonly class AutoloadIncludedFilesProbe implements IncludedFilesProbeInterface
{
    /** The script, under the library root. */
    public const string SCRIPT = '/bin/autoload-included-files';

    /** What the script prints before its JSON list; bin/autoload-included-files holds the same literal. */
    public const string MARKER = 'PHPQACI-AUTOLOAD-INCLUDED-FILES ';

    /** The reason given when what follows MARKER is not a list of paths. */
    private const string NOT_A_LIST = 'what it printed as the files it included is not a JSON list of paths';

    public function includedFiles(ToolContext $context, string $autoload): array|string
    {
        $paths  = $context->config->paths;
        $result = $context->php->withoutXdebug($paths->libraryRoot . self::SCRIPT, [$autoload], $paths->projectRoot, streamOutput: false);
        if (!$result->succeeded()) {
            return \sprintf("it exited %d:\n%s", $result->exitCode, trim($result->output));
        }

        $marker = strrpos($result->stdout, self::MARKER);
        if (false === $marker) {
            return 'it printed no list of the files it included (a files entry that exits stops it before it can)';
        }

        $json    = substr($result->stdout, $marker + \strlen(self::MARKER));
        $decoded = json_validate($json) ? \Safe\json_decode($json, true) : null;
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return self::NOT_A_LIST;
        }

        $files = [];
        foreach ($decoded as $file) {
            if (!\is_string($file)) {
                return self::NOT_A_LIST;
            }

            $files[] = $file;
        }

        return $files;
    }
}
