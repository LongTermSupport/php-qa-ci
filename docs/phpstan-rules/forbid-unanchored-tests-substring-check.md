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

## Why this is a hazard

`$scope->getFile()` and `ClassReflection::getFileName()` return absolute paths. The substring test
assumes that nothing above the project root is named `tests`, and a checkout at
`/home/ci/tests/my-app/`, or any CI workspace or fixture directory called `tests`, breaks that
assumption. Every file of the project then matches, so a rule that exempts test code exempts the
whole project and reports nothing.

That is a silent failure. The run is green, and the defence the rule provides is absent in exactly
the environments nobody inspects. A namespace check has the same shape:
`str_contains($namespace, 'Tests')` holds for `App\Contests\Entry`, so production code in such a
namespace is treated as test code too.

## The correct construction

Inject `TestCodeDetector` and ask it. It is wired from PHPStan's `%currentWorkingDirectory%`, which
PHPStan sets to the analysed project's root, so a file is test code when it is under
`<project root>/tests/`, and a namespace is test code when one of its segments is exactly `Tests`:

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

It sees a literal and a class constant. A `tests/` fragment built at runtime, or held in a variable,
is not reported. A namespace substring check (`'Tests'` with no slash) is not reported, because
the word alone is as often a class-name suffix as a path; review such a check by hand and compare
whole namespace segments.

[`phpqaci.unanchoredVendorSubstringCheck`](forbid-unanchored-vendor-substring-check.md) is the same
rule for deciding whether code is a dependency's.
