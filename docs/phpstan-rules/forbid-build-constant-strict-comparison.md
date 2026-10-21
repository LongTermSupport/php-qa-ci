# `phpqaci.buildConstantStrictComparison` — read `PHP_ZTS` and `PHP_DEBUG` through `(bool)`

**Rule**: `ForbidBuildConstantStrictComparisonRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

`PHP_ZTS` or `PHP_DEBUG` read as it is wherever the result can depend on its type:

- compared with `===` or `!==`, as the subject of a `match` or as a `match` arm, all of which
  compare strictly;
- compared with `==`, `!=`, `<`, `<=`, `>`, `>=` or `<=>`, or switched on, against anything other
  than a literal whose result is the same for both types (`0`, `1`, `true`, `false`, `'0'`, `'1'`
  and the like) or the other build constant;
- converted to a string: `(string)`, `.`, `.=`, `echo`, `print`;
- stored or handed on: assigned to a variable or property, returned, yielded, a parameter default,
  a constant's value, an array value, an argument to any function but `boolval()`, `intval()` and
  `floatval()`, `(array)`, `(object)`;
- `~`, which throws a `TypeError` on a `bool`.

`??`, `?:`, a ternary branch and a `match` arm's result hand the value on unchanged, so a use
through them is judged where the value ends up: `(bool) ($zts ?? \PHP_ZTS)` is not reported,
`1 === ($zts ?? \PHP_ZTS)` is.

```php
$threadSafe = 1 === \PHP_ZTS;
$zts        = \PHP_ZTS;                       // and later: 1 === $zts
$found      = in_array(\PHP_ZTS, [1], true);
$key        = 'phpstan-' . \PHP_DEBUG;        // 'phpstan-0' or 'phpstan-'
$isInt      = is_int(\PHP_ZTS);
```

## Why this is a hazard

The two constants say how the running PHP was built, and their type depends on the PHP version:
PHP 8.4 and later define them as `bool`, PHP 8.3 and earlier as an `int` (0 or 1). A strict
comparison against either type is therefore always false on the other: `1 === \PHP_ZTS` is
`false` on every PHP from 8.4 on, thread-safe or not, so code that picks a thread-safe binary,
extension or path quietly picks the non-thread-safe one on a ZTS build. A string conversion gives
`'1'` or `''` on one and `'1'` or `'0'` on the other, so a cache key or a file name differs. A type
test, a JSON encoding or a typed parameter under `strict_types` sees a different type.

PHPStan cannot report it: it types both constants as `0|1` whatever the PHP version, so
`1 === \PHP_ZTS` looks like a sound test to it, and so does every later use of a variable that
holds the raw value. The defect only shows on the build the code was written for, which is
usually not the one the author tests on. That is why the rule reports the raw value stored or
passed on, not only the comparison: once it is in a variable, nothing can tell it apart from an
`int`.

## The correct construction

Cast the constant to `bool`, which reads `0`/`1` and `false`/`true` alike, and use the result:

```php
$threadSafe = (bool) \PHP_ZTS;
$suffix     = (bool) \PHP_ZTS ? '-zts' : '';
$key        = 'phpstan-' . ((bool) \PHP_DEBUG ? 'debug' : 'release');
```

This is how phpstan.phar's own `TurboExtensionSelector` reads both constants. Pass the `bool`
along rather than the raw constant, so no later comparison sees the version-dependent type.

## What is still allowed

A use that reads only the constant's truth or its number, which is the same for `0`/`1` and
`false`/`true`: `(bool)`, `(int)`, `(float)`, `boolval()`, `intval()`, `floatval()`, `!`, `&&`,
`||`, `and`, `or`, `xor`, `empty()`, the condition of `if`, `elseif`, `while`, `do`, `for` and a
full ternary, arithmetic and bitwise operators other than `~`, unary minus and plus, an array key
or offset, and a comparison or `switch` whose result the rule can show to be the same on both
types. A loose `==` is still reported by [`phpqaci.looseComparison`](forbid-loose-comparison.md)
in its own right.

## If you believe an instance is legitimate

A use of the raw value is correct on one family of PHP versions only, so code that supports
exactly one of them could depend on that type. The cast is still right there and costs nothing;
prefer it. An irreducible case belongs in `ignoreErrors` in the project's `phpstan.neon`, with a
path and this identifier.

## Defence record (#268)

- **Class**: a runtime constant whose type changed between PHP versions, read in a way that
  depends on its type. **Hazard**: the code behaves differently on the PHP versions on either side
  of the change, and PHPStan, which types the constant the same on all of them, cannot report it.
- **Members**: `PHP_ZTS` and `PHP_DEBUG`, the only constants the `UPGRADING` notes of PHP 8.4 and
  PHP 8.5 record as changing type. PHP 8.5 changes the values of some `PDO::FETCH_*` constants,
  which is a different class (a literal standing in for a constant), not a type change.
- **Searches**: a text search for both names over `src/`, `tests/` and `bin/`, and a reading of
  the code that consumes the value (`TurboPlatform`, `PhpstanSetupKey::RUNTIME_PROBE`). The probe
  is code in a string, run by another PHP and hashed only as a cache key, so it has no hazard.
- **Arguments**: an argument counts as type-dependent for any function but the three casts. Under
  `strict_types` a typed parameter throws a `TypeError` on one side of the change, and an untyped
  one receives the type; the rule does not model each callee to find the few that ignore it. A
  constant of the same name declared in a namespace and reached unqualified is taken to be the
  global one.
- **Next wider rule**: the raw value flowing through a variable to a sink is reported at the
  store, not followed; following it needs data flow the rule does not have. The upstream fix,
  PHPStan typing both constants as `bool` from `phpVersion` 8.4, would let PHPStan's own type
  checks report every sink.
- **Runner check**: `TurboPlatformTest` pins that a thread-safe PHP is given the `-zts` binary
  whether the constant is `1` or `true`.
