<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * Shipped default for the composerDependencyAnalyser lane. Copy to
 * qaConfig/composer-dependency-analyser.php to replace it; the project copy
 * wins outright, it is not merged.
 *
 * The paths to scan come from composer.json's own autoload sections, so this
 * file only records what the pipeline's own architecture makes unavoidable.
 */

return new Configuration()
    /*
     * php-qa-ci runs PHPStan, PHPArkitect and Rector from PHARs rather than
     * from the consumer's vendor/, so a qaConfig/ file that configures one of
     * them names classes Composer cannot autoload. That is the design, not a
     * missing dependency, and reporting it would train the reader to ignore
     * the unknown-symbol output entirely.
     */
    ->ignoreErrorsOnPath('qaConfig', [ErrorType::UNKNOWN_CLASS, ErrorType::UNKNOWN_FUNCTION])
    /*
     * This file serves every project that has no copy of its own, so an
     * ignore above that a given project never trips is expected, not stale.
     * The tool would otherwise exit 1 for the unmatched ignore on a project
     * with nothing wrong.
     */
    ->disableReportingUnmatchedIgnores();
