<?php

declare(strict_types=1);
if (!isset($projectRoot) || !is_dir($projectRoot)) {
    throw new RuntimeException('$projectRoot must be defined and must be a valid path to the project root');
}

/*
 * A namespace-less config-template script (mirrors php_cs_finder.php). This
 * fixture's ignore-list does NOT cover it — this is the pattern the auditor
 * exists to catch: a documented copy-override of this file into a project's
 * qaConfig/ (a PSR-4 root) would fail psr4Validate with a false "Parse
 * Errors" report, because nothing in the ignore list matches
 * "qaConfig/uncovered_template.php".
 */
return ['covered' => false, 'projectRoot' => $projectRoot];
