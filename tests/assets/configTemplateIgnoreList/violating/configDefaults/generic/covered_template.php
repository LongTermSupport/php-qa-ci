<?php

declare(strict_types=1);
if (!isset($projectRoot) || !is_dir($projectRoot)) {
    throw new RuntimeException('$projectRoot must be defined and must be a valid path to the project root');
}

/*
 * A namespace-less config-template script (mirrors php_cs_finder.php). This
 * fixture's ignore-list DOES cover it — used to prove the auditor stays quiet
 * on a properly-covered template, not just noisy on an uncovered one.
 */
return ['covered' => true, 'projectRoot' => $projectRoot];
