<?php

declare(strict_types=1);
if (isset($GLOBALS['projectRoot'])) {
    throw new \RuntimeException('$projectRoot must be defined and must be a valid path to the project root');
}

return (new PhpCsFixer\Finder())
    ->in($projectRoot)
    ->exclude(
        [
            'var',
            'tests/assets/psr4',
        ]
    )
;
