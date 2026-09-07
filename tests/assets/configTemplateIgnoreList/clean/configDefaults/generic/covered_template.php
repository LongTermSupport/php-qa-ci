<?php

declare(strict_types=1);
if (!isset($projectRoot) || !is_dir($projectRoot)) {
    throw new RuntimeException('$projectRoot must be defined and must be a valid path to the project root');
}

/*
 * A namespace-less config-template script, fully covered by the ignore list
 * below — the "everything is fine" fixture.
 */
return ['covered' => true, 'projectRoot' => $projectRoot];
