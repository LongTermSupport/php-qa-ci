# `phpqaci.buildConstantStrictComparison` — read `PHP_ZTS` and `PHP_DEBUG` through `(bool)`

**Rule**: `ForbidBuildConstantStrictComparisonRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

`PHP_ZTS` or `PHP_DEBUG` on either side of `===` or `!==`, or as the subject of a `match`, which
compares strictly too.

```php
$threadSafe = 1 === \PHP_ZTS;
$debug      = \PHP_DEBUG !== 0;
$suffix     = match (\PHP_ZTS) { 1 => '-zts', 0 => '' };
```

## Why this is a hazard

The two constants say how the running PHP was built, and their type depends on the PHP version:
PHP 8.5 defines them as `bool`, older PHP as an `int` (0 or 1). A strict comparison against
either type is therefore always false on the other. `1 === \PHP_ZTS` is `false` on every PHP 8.5,
thread-safe or not, so code that picks a thread-safe binary, extension or path quietly picks the
non-thread-safe one on a ZTS build.

PHPStan cannot report it: it types both constants as `0|1` whatever the PHP version, so
`1 === \PHP_ZTS` looks like a sound test to it. The defect only shows on the build the code was
written for, which is usually not the one the author tests on.

## The correct construction

Cast the constant to `bool`, which reads `0`/`1` and `false`/`true` alike, and use the result:

```php
$threadSafe = (bool) \PHP_ZTS;
$suffix     = (bool) \PHP_ZTS ? '-zts' : '';
```

This is how phpstan.phar's own `TurboExtensionSelector` reads both constants. Pass the `bool`
along rather than the raw constant, so no later comparison sees the version-dependent type.

## What is still allowed

`(bool) \PHP_ZTS` compared with `true` or `false`, any other constant compared strictly, and a
loose `==` comparison, which [`phpqaci.looseComparison`](forbid-loose-comparison.md) reports in
its own right.

## If you believe an instance is legitimate

A strict comparison of these constants is correct on one family of PHP versions only, so code that
supports exactly one of them could compare against that type. The cast is still right there and
costs nothing; prefer it. An irreducible case belongs in `ignoreErrors` in the project's
`phpstan.neon`, with a path and this identifier.
