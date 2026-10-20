# `phpqaci.unanchoredTestsSubstringCheck` — decide test code against the project root, not a substring

**Rule**: `ForbidUnanchoredTestsSubstringCheckRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call to `str_contains`, `strpos`, `stripos`, `str_starts_with` or `str_ends_with` where one
argument is a string literal containing `tests/`, or a class constant whose value is such a string.

```php
if (str_contains($scope->getFile(), '/tests/')) {
    return []; // "test code, skip it"
}
```

A fragment concatenated onto a root variable does not fire, because that is the anchored form this
rule steers you towards:

```php
if (str_starts_with($fileName, $this->projectRoot . '/tests/')) {
```

The same check on a namespace or class name fires too: `str_contains`, `strpos` or `stripos` where
one argument is exactly `Tests`, as a literal or as a variable PHPStan knows may hold it, such as
the value of a loop over a constant list:

```php
foreach (['PHPStan', 'Tests', 'Generated'] as $word) {
    if (str_contains($className, $word)) {
        return []; // App\TestsHelper\Mailer is skipped too
    }
}
```

`str_starts_with` and `str_ends_with` are anchored at one end and do not fire for the word, nor does
a needle bounded by separators such as `'\Tests\\'`.

## Why this is a hazard

`$scope->getFile()` and `ClassReflection::getFileName()` return absolute paths. The substring test
assumes that nothing above the project root is named `tests`, and a checkout at
`/home/ci/tests/my-app/`, or any CI workspace or fixture directory called `tests`, breaks that
assumption. Every file of the project then matches, so a rule that exempts test code exempts the
whole project and reports nothing.

That is a silent failure. The run is green, and the defence the rule provides is absent in exactly
the environments nobody inspects. A namespace check has the same shape:
`str_contains($namespace, 'Tests')` holds for `App\TestsHelper\Entry`, so production code in such a
namespace is treated as test code too.

## The correct construction

Inject `TestCodeDetector` and ask it. It is wired from PHPStan's `%currentWorkingDirectory%`, which
PHPStan sets to the analysed project's root, so a file is test code when it is under
`<project root>/tests/`, and a namespace is test code when one of its segments ends with `Tests`,
case-sensitively (`App\Tests`, `App\FunctionalTests`; not `App\TestsHelper` or `App\Contests`):

```php
public function __construct(
    private TestCodeDetector $testCodeDetector,
) {
}

if ($this->testCodeDetector->isTestCode($scope->getFile(), $scope->getNamespace())) {
    return [];
}
```

Where a rule cannot take the detector, anchor the check on the project root yourself, with the root
wired from the same parameter in `rules-default.neon`:

```php
if (str_starts_with($fileName, rtrim($this->projectRoot, '/') . '/tests/')) {
```

## What this rule does not cover

For the `tests/` path fragment it sees a literal and a class constant; a fragment built at runtime,
or held in a variable, is not reported, because a constant-valued `__DIR__ . '/tests/'` is anchored
and would be reported wrongly. For the `Tests` word it sees any value PHPStan knows, but only the
exact word: a needle such as `'Tests\\'` or `'IntegrationTests'` is not reported.

[`phpqaci.unanchoredVendorSubstringCheck`](forbid-unanchored-vendor-substring-check.md) is the same
rule for deciding whether code is a dependency's.
