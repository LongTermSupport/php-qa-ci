<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;

/**
 * The project's ignored paths as a PHPStan `excludePaths` parameter, for the
 * wrapper neon the phpstan and deadCode lanes write. Both lanes write the same
 * block, so the dead-code run sees exactly the files the gate does.
 *
 * Every entry goes under `analyse`, not `analyseAndScan`. An ignored path
 * holds code nobody wants REPORTED (fixtures with deliberate violations,
 * generated code), but analysed code may still reference it: a test loads a
 * fixture class, production calls into generated code. `analyse` keeps those
 * files out of the report while PHPStan can still discover the symbols they
 * declare; `analyseAndScan` would turn every such reference into a
 * class.notFound in the code that IS analysed.
 *
 * Every entry is marked optional with `(?)`. PHPStan refuses to start when a
 * required excludePaths entry exists nowhere, and an ignored path is a
 * project-wide setting that a checkout without the generated directory yet
 * legitimately lacks. The paths are absolute because PHPStan resolves a
 * relative entry against the neon that declares it, which is the generated
 * wrapper under var/qa/, not the project root.
 *
 * PHPStan normalises a plain-list `excludePaths` in any included neon into the
 * same `analyseAndScan` / `analyse` structure before merging, so this block
 * combines with whatever list the project's own phpstan.neon carries.
 *
 * @internal
 */
final readonly class ExcludePathsNeon
{
    /** The block, indented to sit under `parameters:`; empty when nothing is ignored. */
    public function parameters(IgnoredPaths $ignored): string
    {
        if ($ignored->isEmpty()) {
            return '';
        }

        $neon = "    excludePaths:\n        analyse:\n";
        foreach ($ignored->absolute as $path) {
            $neon .= \sprintf("            - '%s' (?)\n", str_replace("'", "''", $path));
        }

        return $neon;
    }
}
