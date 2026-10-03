<?php

declare(strict_types=1);

// Override $projectRoot from parent scope — the PHAR-based fallback in php_cs.php
// computes the wrong path when the library IS the root project (goes too many levels up).
// Use the actual working directory instead.
$projectRoot = getcwd() ?: $projectRoot;

// tests/assets is not excluded here: it is in qa.php's withIgnoredPaths(), and
// the phpCsFixer lane takes the ignored paths out of whatever finder this is.
return (new PhpCsFixer\Finder())
    ->in($projectRoot)
    ->exclude(
        [
            'var',
        ]
    )
    ->ignoreVCSIgnored(true)
;
