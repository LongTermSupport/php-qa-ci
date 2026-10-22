<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/*
 * PHP 8.5 specific Rector configuration for php-qa-ci
 * This runs after rector-safe.php and rector-phpunit.php
 */
return static function (RectorConfig $rectorConfig): void {
    // Rector's cache lives in the project, never in the system temp directory, which every project
    // and every concurrent run on the host would share. The pipeline exports the directory in
    // rectorCacheDir (var/qa/cache/rector); standalone, the same path under the working directory.
    // There is no cacheClass() call to copy into a project config: this is the cache setting.
    $cacheDir = (string) ($_SERVER['rectorCacheDir'] ?? (getcwd() . '/var/qa/cache/rector'));
    // Rector requires the container cache directory to exist; --clear-cache empties only the files directory.
    foreach ([$cacheDir . '/files', $cacheDir . '/container'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the Rector cache directory ' . $directory);
        }
    }
    $rectorConfig->cacheClass(FileCacheStorage::class);
    $rectorConfig->cacheDirectory($cacheDir . '/files');
    $rectorConfig->containerCacheDirectory($cacheDir . '/container');

    // Limit parallel processing to use only half of available CPU threads
    // to avoid overwhelming the system
    $cpuThreads = (int) shell_exec('nproc') ?: 4;  // Default to 4 if nproc fails
    $maxProcesses = max(1, (int) floor($cpuThreads / 2));  // Use half the threads, minimum 1

    // Parameters: timeout (seconds), max processes, job size (files per job)
    $rectorConfig->parallel(
        120,  // Default timeout
        $maxProcesses,  // Use only half of available CPU threads
        16    // Default job size
    );

    // PHP 8.5 upgrade sets. UP_TO_PHP_85 is cumulative (includes every earlier
    // version set, e.g. PHP 8.4's ExplicitNullableParamTypeRector), so no
    // version-specific rules need listing separately.
    // IMPORTANT: SetList::NAMING is EXCLUDED because it includes RenameParamToMatchTypeRector
    // which renames constructor parameters like $productsRow → $productsRowDto
    // This is a BREAKING CHANGE for any code calling constructors with named parameters
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_85,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
        // SetList::NAMING, // EXCLUDED - causes constructor parameter renaming
    ]);

    // TernaryToNullCoalescingRector rewrites `null === $x ? '' : $x` (and the isset()/!== null ternary
    // variants defaulting to '') into `$x ?? ''` — which this package's own opt-in
    // ForbidNullCoalescingEmptyStringRule (rules-optional.neon) then BANS. The two rules contradict by
    // construction: Rector mechanically produces exactly the pattern the PHPStan rule forbids, so a file
    // carrying such a ternary can never be made green (Rector rewrites it on every writable run, PHPStan
    // then fails it). PHPStan is the stronger guarantee here — a `?? ''` default is almost always
    // error-hiding and should be an explicit null check — and the rare LEGITIMATE null->'' case
    // (canonicalising a nullable into a hash/serialisation preimage, where the absent marker must differ
    // from every present value) must stay written as a ternary: its mutation set is fully killable,
    // whereas `?? ''` yields an UNKILLABLE equivalent mutant (Infection's Coalesce mutator reduces
    // `$x ?? ''` to `$x`, indistinguishable once null and '' coerce identically downstream). So we keep
    // the ban and drop the Rector rule that manufactures its violation.
    //
    // NullToStrictStringFuncCallArgRector is kept out for the same reason: it casts a possibly-null
    // argument to (string), and where PHPStan already proves a string, level max rejects the cast. No
    // set above registers it, so it is not in the list (Rector warns on a skip it never registers);
    // RectorSkipListMatchesTheShippedSetsTest fails if a Rector release puts it back into a set.
    //
    // SplitGroupedClassConstantsRector splits `const int B = 2, C = 3;` without copying the type to
    // the new statements, so `C` loses `int` in any class-like AddTypeToConstRector does not re-type
    // (one that is not final, an interface, a trait). PHP CS Fixer's single_class_element_per_statement
    // makes the same split and keeps the type, so the split is left to it.
    // ShippedRectorKeepsConstantTypesTest fails if the type is lost again.
    $rectorConfig->skip([
        Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector::class,
        Rector\CodingStyle\Rector\ClassConst\SplitGroupedClassConstantsRector::class,
    ]);

    // Support for ignoring paths via environment variable
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
