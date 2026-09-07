# `phpqaci.missingStrictTypes` — every file declares strict types

**Rule**: `RequireDeclareStrictTypesRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Any PHP file without `declare(strict_types=1)`.

## Why this is a hazard

Without it, PHP coerces scalar arguments and return values at every typed boundary in the file. A
function declared `f(int $q)` accepts `"12"`, `12.9` and `true`, silently converting each.

The coercions are lossy and the losses are quiet. `12.9` becomes `12`, so a quantity is wrong by
one. `"12 items"` becomes `12` with a notice nobody reads. A user id arriving as `"0123"` from a
form becomes `123`, which is a different row.

The declaration is per-file, which is what makes the rule necessary rather than merely advisable.
Adding it to most files does not protect the others, and a single missing declaration reintroduces
coercion for everything that file touches. A convention that has to hold in every file is a
convention that has to be enforced mechanically.

## The correct construction

```php
<?php

declare(strict_types=1);

namespace App\Service;
```

The declaration must be the first statement after the opening tag, before the namespace. PHP will
throw a fatal error if it appears anywhere else, so there is no ambiguity about placement.

## Fixing an existing codebase

Rector adds the declaration across a codebase in one pass, and php-qa-ci runs Rector as its first
phase, so `bin/qa` will often fix this before the analysis stage ever sees it.

Adding the declaration to a file that was previously relying on coercion **will** surface real type
errors. That is the rule working: each one is a place where a value of the wrong type was being
quietly converted. Fix them by correcting the type at its source rather than by casting at the call
site, since a cast is the coercion you just turned off, reintroduced by hand.

## If you believe an instance is legitimate

Generated code and vendored third-party source are the usual candidates. Exclude them by path in the
project's `phpstan.neon` under `excludePaths`, so the exclusion is scoped to files nobody edits
rather than expressed as an ignored error. Do not suppress the rule for hand-written source: the
file that most needs strict types is the one somebody is about to change.
