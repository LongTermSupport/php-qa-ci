<?php

declare(strict_types=1);

// Override $projectRoot from parent scope — the PHAR-based fallback in php_cs.php
// computes the wrong path when the library IS the root project (goes too many levels up).
// Use the actual working directory instead.
$projectRoot = getcwd() ?: $projectRoot;

return (new PhpCsFixer\Finder())
    ->in($projectRoot)
    ->exclude(
        [
            'var',
            'tests/assets',
        ]
    )
    ->ignoreVCSIgnored(true)
;
