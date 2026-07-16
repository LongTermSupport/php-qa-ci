# Research — Building a Rector PHAR with humbug/box

**Date**: 2026-07-16
**Author**: research (web + local verification)
**Status**: Findings + concrete build recipe

## Goal

Determine whether we can reliably compile `rector/rector` 2.x into a
self-contained `rector.phar`, what tooling is needed, and whether
`php-scoper` is required on top of Box.

## Tooling

- **`humbug/box`** — "fast, zero-config application bundler with PHARs". Looks
  for `box.json` / `box.json.dist` in the CWD; `box compile` builds the PHAR.
  Box has **built-in php-scoper integration** (it will run php-scoper during
  compile if configured).
- **`humbug/php-scoper`** — prefixes PHP namespaces to isolate bundled code.
  Needed only when the PHAR's classes get **loaded into a process alongside a
  conflicting version** of the same library.

## Do we need php-scoper for Rector? — NO (for our model)

This is the pivotal decision. php-scoper is required when a tool is loaded
*in-process* next to conflicting code (e.g. a PHPUnit extension, or a library
`require`d into a host app). It is **not** required when the PHAR is executed as
its **own standalone process**, which is exactly how php-qa-ci runs every tool:
`phpNoXdebug -f "$pharDir"/rector.phar`.

Reasons php-scoper is unnecessary here:

1. **Rector is already internally prefixed.** Its vendor tree (Symfony, Doctrine,
   nikic, …) is shipped under `RectorPrefix202xxx\…` by the Rector project's own
   scoping. Re-scoping is redundant.
2. **The only unprefixed dep is `phpstan/phpstan`.** Inside a standalone
   `rector.phar` running in its own process, that bundled PHPStan never coexists
   with php-qa-ci's `phpstan.phar` — those are two separate processes.
3. Box's default `compact`/`compress` gives the size/perf win without scoping.

**IMPORTANT correction (fable review 1, §1a) — there IS one in-process surface,
and it is NOT new.** `rector.inc.bash` passes
`--autoload-file "$projectRoot/vendor/autoload.php"`, which Rector `require`s
into the phar's process. If a consumer composer-requires `phpstan/phpstan`, the
consumer's phpstan and the phar's bundled phpstan coexist in one process — the
same surface behind rector#4606's "Cannot redeclare `PHPStan\dumpType()`". This
is **identical to today's `tools/rector/` model** (unprefixed phpstan in Rector's
vendor + consumer autoloader loaded on top), so the phar is **not a regression** —
but "collision structurally impossible" is WRONG and must not be relied on. The
spike therefore includes a fixture consumer that composer-requires phpstan.

**Net: `box compile` over the existing composer project is expected to be
sufficient — no `scoper.inc.php` required** — but this is a spike hypothesis to
prove, not a guarantee. php-scoper stays a documented fallback if the spike
surfaces a real collision.

> Risk note to validate in the spike: Rector loads rule configs and autoloads
> user code at runtime (`--autoload-file`). PHARs historically caused Rector
> path-resolution grief (the reason upstream dropped their PHAR — 143 issues).
> Our usage is narrower than upstream's general-purpose PHAR (we always pass an
> explicit `--config` and `--autoload-file "$projectRoot/vendor/autoload.php"`,
> and set `--clear-cache`), so most of those edge cases don't apply — but the
> spike MUST prove a real `rector process` run works from the PHAR before we
> commit to it. This is the single largest feasibility risk in the plan.

## Concrete build recipe (to validate in the spike)

Starting point: the existing `tools/rector/` composer root already resolves
`rector/rector` + its `phpstan/phpstan` into `tools/rector/vendor`.

1. **Environment**: building a PHAR requires `phar.readonly=0`. Verified locally
   `phar.readonly=1`, so build invocation must use `php -d phar.readonly=0`.
   PHP is 8.4.23 locally; Box itself must run on a supported PHP.
2. **Install Box** as a PHAR (do NOT composer-require it into `tools/rector` —
   that would pull Box's own deps into Rector's graph). Either PHIVE it
   (`box` is a phar.io-registered alias) or download the release phar. This
   mirrors how php-scoper is normally installed as a phar to avoid self-scoping.
3. **`box.json.dist`** (minimal, in `tools/rector/` or a dedicated build dir):
   ```json
   {
     "main": "vendor/rector/rector/bin/rector.php",
     "output": "rector.phar",
     "directories": ["vendor"],
     "compactors": [
       "KevinGH\\Box\\Compactor\\Php"
     ],
     "compression": "GZ",
     "banner": false
   }
   ```
   - `main` = Rector's console entry (confirm exact path in vendor; Rector's
     bin is `vendor/bin/rector` → real file `vendor/rector/rector/bin/rector.php`).
   - No `php-scoper` key ⇒ Box does not scope (desired).
   - `compression: GZ` keeps the committed phar small (Rector+phpstan is large;
     phpstan.phar alone is ~27 MB here).
4. **Build**: `php -d phar.readonly=0 box.phar compile --working-dir=tools/rector`.
5. **Smoke test the artifact** (gate): run the built phar end-to-end against a
   throwaway file with a real rector-safe config:
   `php rector.phar process /tmp/x.php --config=... --dry-run --clear-cache`
   and assert it refactors/reports correctly. This is the go/no-go signal.

## Size / storage consideration

The other committed phars total ~34 MB (`phpstan.phar` ~27 MB dominates). A
Rector phar bundles Rector + a full phpstan, so expect **~20–30 MB**. Committing
it to `vendor-phar/` follows the existing pattern but grows the repo. GZ
compression and git's delta handling of a mostly-append-only binary mitigate
this; quantify the actual size in the spike and note it as an accepted cost
(consumers already pull ~34 MB of phars).

## Automation to keep Rector current

Building manually rots. Two options (detailed in the phive-distribution research):
- a maintainer `make`/script target invoked in the existing `update` mode; or
- a scheduled GitHub Actions workflow that rebuilds on new Rector releases,
  (optionally) signs, and commits/attaches the phar.

## Sources

- [humbug/box — Packagist](https://packagist.org/packages/humbug/box)
- [box-project/box (GitHub)](https://github.com/box-project/box)
- [humbug/php-scoper (GitHub)](https://github.com/humbug/php-scoper)
- [php-scoper configuration docs](https://github.com/humbug/php-scoper/blob/main/docs/configuration.md)
- [How to Scope Your PHP Tool in 10 Steps | Tomas Votruba](https://tomasvotruba.com/blog/how-to-scope-your-php-tool-in-10-steps)
- [Box integration · humbug/php-scoper#49](https://github.com/humbug/php-scoper/issues/49)
- [PHARs roadmap | Théo Fidry (Medium)](https://medium.com/@tfidry/phars-roadmap-870671a847c1)
- Local: `php -d phar.readonly`, `tools/rector/composer.lock`, `vendor-phar/` sizes
