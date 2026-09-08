<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/*
 * PHP 8.5 specific Rector configuration for php-qa-ci
 * This runs after rector-safe.php and rector-phpunit.php
 */
return static function (RectorConfig $rectorConfig): void {
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

    // NullToStrictStringFuncCallArgRector wraps a possibly-null argument to a string function in a
    // (string) cast (the PHP 8.1 "passing null to a non-nullable internal param" deprecation). Rector
    // cannot see PHPStan's flow narrowing, so where PHPStan already proves the argument is a string
    // (e.g. after assertNotNull, or via an array-shape type) the added cast is redundant and
    // php-qa-ci's PHPStan-max rejects it as "casting to string something that's already string". The
    // defensive cast contradicts PHPStan-max by construction; PHPStan is the stronger guarantee (it
    // makes you fix the nullability explicitly), so we keep it and drop this cast-adding rule.
    //
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
    // the ban and drop the Rector rule that manufactures its violation — the same stance, for the same
    // reason, as the NullToStrictStringFuncCallArgRector skip above.
    $rectorConfig->skip([
        Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
        Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector::class,
    ]);

    // Support for ignoring paths via environment variable
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
