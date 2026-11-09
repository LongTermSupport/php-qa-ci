<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

/*
 * PHPUnit upgrade rules are selected by PHPUnitSetList::COMPOSER_BASED, which
 * picks the rules matching the PHPUnit version locked in the project's
 * composer.lock; Rector exposes no versioned set constants (PHPUNIT_100 etc.).
 * The rule CLASSES under the versioned namespaces still exist, which is why
 * individual ones (e.g. PHPUnit100\...\ParentTestClassConstructorRector) can be
 * skipped by class name below.
 */

return static function (RectorConfig $rectorConfig): void {
    // Rector's cache lives in the project, never in the system temp directory, which every project
    // and every concurrent run on the host would share. The pipeline exports the directory in
    // rectorCacheDir (var/qa/cache/rector); standalone, the same path under the working directory.
    // Rector chooses the cache storage itself (RectorConfig has no cacheClass()): this is the cache setting.
    $cacheDir = (string) ($_SERVER['rectorCacheDir'] ?? (getcwd() . '/var/qa/cache/rector'));
    // Rector requires the container cache directory to exist; --clear-cache empties only the files directory.
    foreach ([$cacheDir . '/files', $cacheDir . '/container'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the Rector cache directory ' . $directory);
        }
    }
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

    $rectorConfig->sets([
        PHPUnitSetList::COMPOSER_BASED,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector::class]);
    $rectorConfig->skip([Rector\PHPUnit\PHPUnit100\Rector\Class_\ParentTestClassConstructorRector::class]);
    // ScalarArgumentToExpectedParamTypeRector incorrectly converts test values that have semantic meaning
    // e.g., it changes name: '' to name: 0 when the parameter accepts int|string
    // but in tests, '' and 0 have very different meanings and test different code paths
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\MethodCall\ScalarArgumentToExpectedParamTypeRector::class]);
    // StringCastAssertStringContainsStringRector adds a (string) cast to the haystack of
    // assertStringContainsString(). Rector cannot see PHPStan's flow narrowing, so for the idiomatic
    // `assertNotNull($x); assertStringContainsString(..., $x);` pattern (where $x is ?string proven
    // non-null) it casts a value PHPStan already knows is a string — which php-qa-ci's PHPStan-max then
    // rejects as "casting to string something that's already string". The two tools shipped here would
    // contradict each other on correct code with no fix that satisfies both. PHPStan is the stronger
    // guarantee, so we keep it and drop the redundant-cast-adding rule.
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\MethodCall\StringCastAssertStringContainsStringRector::class]);
    // FlipAssertRector moves a scalar/constant argument to the left on the premise that it is the
    // "expected" half of an expected/actual pair. That premise holds for assertSame() and friends, but
    // assertStringContainsString() takes (needle, haystack) — there is no expected/actual pair, and the
    // haystack is very often the constant. A correct assertStringContainsString($needle, self::HAYSTACK)
    // is rewritten to assertStringContainsString(self::HAYSTACK, $needle), which asserts the haystack
    // occurs inside the needle: the assertion is INVERTED, not restyled, silently, on code that was
    // right. Rector offers no per-method scoping (the method list is a private const on a rule that is
    // not configurable), so the whole rule goes rather than one method, and the expected-left
    // normalisation for assertSame() is lost with it. A silently inverted assertion is the worse of
    // the two, since it fails on correct code and can pass on wrong code.
    $rectorConfig->skip([Rector\PHPUnit\CodeQuality\Rector\MethodCall\FlipAssertRector::class]);
    // AllowMockObjectsWhereParentClassRector (any subclass of Symfony's TypeTestCase) and
    // AllowMockObjectsForDataProviderRector (a #[DataProvider] test with a mock whose ->method() has no
    // expects()) both add #[AllowMockObjectsWithoutExpectations], once the locked PHPUnit is 12.5.2 or
    // newer. php-qa-ci's own PHPStan rule phpqaci.forbiddenAttribute bans that attribute: createStub()
    // is the construction for a dependency without expectations. Rector re-adds the attribute on every
    // writable run, so fixing one tool breaks the other, and for a TypeTestCase subclass nothing in the
    // test satisfies both. PHPStan wins; both rules go.
    $rectorConfig->skip([
        Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsWhereParentClassRector::class,
        Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector::class,
    ]);
    // AddDoesNotPerformAssertionToNonAssertingTestRector follows $this->m() and self::m() into the
    // method called, but not parent::m(). A test that overrides an asserting parent test to attach a
    // #[DataProvider], and whose body is only parent::<the same method>(), is given
    // #[DoesNotPerformAssertions] although the parent asserts. PHPUnit then reports it risky ("This test
    // is not expected to perform assertions but performed N assertions"), which fails a run under
    // failOnRisky, and Rector re-adds the attribute on every writable run. Rector offers no narrower
    // option, so the whole rule goes: a test that genuinely asserts nothing now carries the attribute by
    // hand. A parent-aware replacement would be a separate change.
    $rectorConfig->skip([Rector\PHPUnit\PHPUnit60\Rector\ClassMethod\AddDoesNotPerformAssertionToNonAssertingTestRector::class]);
    $rectorConfig->rules([
        Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitSelfCallRector::class,
        Rector\PHPUnit\CodeQuality\Rector\Class_\YieldDataProviderRector::class,
    ]);
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
