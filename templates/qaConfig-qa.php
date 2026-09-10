<?php

declare(strict_types=1);

/**
 * Project QA configuration — TEMPLATE.
 *
 *   cp vendor/lts/php-qa-ci/templates/qaConfig-qa.php qaConfig/qa.php
 *
 * The pipeline seeds a QaConfigBuilder from its defaults and the environment
 * (CI, QA_READONLY, phpqaMemoryLimit, useInfection, ...), hands it to this
 * closure, and runs with what the closure returns. Every setting is a typed
 * method; see docs/upgrading-to-8.5.md for the full list.
 *
 * You do not need this file for the defaults: delete any line you do not
 * want to change, or the whole file.
 */

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // Global PHP memory limit for every tool (default 4G).
    ->withMemoryLimit('4G')
    // Project-relative paths the scanning tools skip (fixtures, generated code).
    ->withIgnoredPaths('tests/assets')
    // Mutation-testing floors: raise as the score rises, never lower them.
    ->withInfectionFloors(msi: 60, coveredMsi: 80)
    // Which shell files ShellCheck reads. The default is every git-tracked file
    // with a shell extension or a shell shebang; set this only to override that,
    // and note a list matching nothing fails the lane.
    // ->withShellCheckGlobs('scripts/*.bash', 'bin/deploy')
    // Generated code PHPArkitect must not judge, relative to src/.
    // ->withArkitectExcludedPaths('Quote/API')
    // A project that genuinely handles no secrets may opt out of the
    // #[\SensitiveParameter] usage check.
    // ->withSensitiveParameterCheck(false)
    // Dead-code detection (shipmonk/dead-code-detector through phpstan.phar).
    // Enabling it requires naming the PHP entry-point scripts the detector
    // must analyse, or stating there are none with withoutDeadCodeEntryPoints().
    // ->withDeadCodeDetection(true)
    // ->withDeadCodeEntryPoints('bin/console')
;
