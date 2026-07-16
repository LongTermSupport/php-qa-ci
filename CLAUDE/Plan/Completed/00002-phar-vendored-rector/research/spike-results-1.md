# Spike Results 1 — Phase 1 de-risking (Rector-from-PHAR)

**Date**: 2026-07-16
**Executor**: coordinator (Opus), non-destructive build in the session scratchpad
**Verdict**: **GREEN — feasible, but ONLY with a phpstan.phar extraction step.**
The naive "box compile" is **RED** (fatal on boot). The corrected build is proven
working end-to-end against the real php-qa-ci Rector configs.

## Environment

- Box **4.7.0** (downloaded from box-project/box releases into the scratchpad).
- PHP **8.4.23**, `phar.readonly=1` locally → build uses `php -d phar.readonly=0`.
- Build input: a copy of the repo's existing `tools/rector/` (Rector **2.5.7** +
  `phpstan/phpstan 2.2.x`), staged in the scratchpad (repo untouched).

## THE blocker (naive build — RED)

A straight `box compile` over `tools/rector/vendor` builds a 40.8 MB phar that
**fatals on boot**:

```
Could not check compatibility between Rector\StaticTypeMapper\PhpParser\
IntersectionTypeNodeMapper::mapToPHPStan(): PHPStan\Type\IntersectionType ...
because class PHPStan\Type\IntersectionType is not available in
phar://.../rector.phar/vendor/rector/rector/src/StaticTypeMapper/PhpParser/IntersectionTypeNodeMapper.php
```

**Root cause (confirmed):** `phpstan/phpstan` does NOT ship loose PHP classes.
Its composer package is just `bootstrap.php` + a nested **`phpstan.phar`** (27 MB)
that holds every `PHPStan\*` class. `bootstrap.php`'s `PharAutoloader` loads them
with `require 'phar://' . __DIR__ . '/phpstan.phar/src/...'`. When this runs
INSIDE `rector.phar`, `__DIR__` is already a `phar://…` path, so the expression
becomes a malformed **nested** `phar://phar://…/phpstan.phar/src/…` — PHP's phar
stream wrapper cannot open a phar inside a phar, so no `PHPStan\*` class ever
loads. This is exactly the "PHAR caused 143 issues" class that made upstream drop
their own PHAR.

## The mitigation (corrected build — GREEN), proven end-to-end

1. **Extract** `phpstan.phar` → a loose `phpstan-src/` dir
   (`(new Phar(...))->extractTo(...)`), and **delete the nested `phpstan.phar`**
   (+ `.asc`) from the build tree.
2. **Patch `bootstrap.php`**: textual replace of
   `'phar://' . __DIR__ . '/phpstan.phar'` → `__DIR__ . '/phpstan-src'`
   (23 path occurrences: the `src/`, `vendor/autoload.php`, better-reflection,
   and all the polyfill `require`s). Inside `rector.phar`, `__DIR__ . '/phpstan-src/src/…'`
   is a valid SINGLE-level in-phar path, so classes load normally.
3. `box compile` the patched tree → `rector-fixed.phar` (39.16 MB uncompressed,
   10 389 files).

### Tests passed (real php-qa-ci configs, dry-run)

| Test | Result |
|---|---|
| Boot: `rector-fixed.phar --version` | ✅ `Rector 2.5.7` |
| `process` with `configDefaults/generic/rector-php84.php` over a single file (loads `LevelSetList`/`SetList` from inside the phar) | ✅ correctly applied `ExplicitNullableParamTypeRector` (`string $x = null` → `?string $x = null`) + `NewlineAfterStatementRector` |
| **Read-only exit code** on a would-change dry-run | ✅ **exit 2** (the exact contract `rector.inc.bash:63-70` depends on) |
| **Parallel worker respawn from the phar** — 40-file corpus (> job size 16) | ✅ all 40 changed, exit 2, no worker-spawn fatal (workers re-exec `PHP_BINARY rector.phar … worker …` from argv[0] correctly) |

## What this means for the plan

- The **core feasibility is proven**: Rector 2.5.7 runs correctly from a
  self-built Box PHAR, including set-list loading and parallel workers, and
  honours the read-only exit-2 contract.
- The "box compile, no php-scoper" hypothesis holds for **scoping** — but it was
  **incomplete**: it MUST be augmented with the phpstan.phar extraction + bootstrap
  patch. This is now a hard, specified requirement of the build script (T1.2/T2.2),
  not an open question.
- php-scoper is still not needed (confirmed): the boot failure was a nested-phar
  problem, not a namespace collision.

## Not yet exercised (deferred to implementation-time spike, lower risk)

- `rector-safe.php` (its `thecodingmachine/safe` preflight — php-qa-ci HAS
  `thecodingmachine/safe ^3.3.0`, so it should pass) and `rector-phpunit.php`.
- Golden-master dry-run diff phar-vs-`tools/rector` over php-qa-ci's own src/tests
  (general drift check; the stub-skip it was meant to catch is moot — see PLAN T1.3).
- Run from the actual vendored layout (`vendor/lts/php-qa-ci/vendor-phar/`).
- GZ-compressed size measurement (uncompressed 39 MB; GZ expected materially
  smaller — quantify before committing, feeds the repo-bloat tripwire).

## Reproduction (scratchpad)

- `box.json` (naive, RED) and `box-build.json` (patched tree, GREEN) in the scratchpad.
- Build tree: `scratchpad/build/` (copy of tools/rector with phpstan extracted +
  bootstrap patched). Artifacts: `rector.phar` (naive), `rector-fixed.phar` (working).
