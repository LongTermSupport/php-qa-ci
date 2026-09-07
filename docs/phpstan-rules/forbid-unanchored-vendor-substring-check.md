# `phpqaci.unanchoredVendorSubstringCheck` — decide ownership against the project root, not a substring

**Rule**: `ForbidUnanchoredVendorSubstringCheckRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call to `str_contains`, `strpos`, `stripos`, `str_starts_with` or `str_ends_with` where one
argument is a bare string literal containing `vendor/`.

```php
if (str_contains($fileName, '/vendor/')) {
    return []; // "third-party, skip it"
}
```

A literal concatenated onto a root variable does not fire, because that is the anchored form this
rule steers you towards:

```php
if (str_starts_with($fileName, $this->projectRoot . '/vendor/')) {
```

## Why this is a hazard

The substring test assumes the analysed project's own source can never sit under a path
containing `vendor/`. It can, and in this package it routinely does: php-qa-ci is developed in
place inside a consuming project's `vendor/lts/php-qa-ci/` checkout. Under that layout every one
of the project's own files matches the substring, so a rule that skips "vendor" code skips the
whole project and reports nothing.

That is a silent failure. The rule does not error, the run is green, and the defence the rule
provides is simply absent in the one environment the rule is most often edited in. This happened to
`ForbidMockingFinalClassRule`, whose own tests failed only when the checkout was under a `vendor/`
path.

## The correct construction

Inject `VendoredCodeDetector` and ask it. It is anchored on the analysed project's own vendor
directory, wired from PHPStan's `%currentWorkingDirectory%`, and it has its own tests for the
nested-checkout case:

```php
public function __construct(
    private ReflectionProvider $reflectionProvider,
    private VendoredCodeDetector $vendoredCodeDetector,
) {
}

if ($this->vendoredCodeDetector->isVendoredCode($classReflection->getFileName())) {
    return [];
}
```

Where a rule cannot take the detector, anchor the check on the project root yourself, with the root
wired from the same parameter in `rules-default.neon`:

```php
if (str_starts_with($fileName, rtrim($this->projectRoot, '/') . '/vendor/')) {
```

`ForbidHttpPrefixedEnvVarsRule` shows the wiring.

## What this rule does not cover

It sees a literal. A `vendor/` fragment built at runtime, or held in a constant and concatenated
without a root, is not reported. The rule's own detection call is excluded by class name, because
its subject is the text of a string node under analysis, not a filesystem path.
