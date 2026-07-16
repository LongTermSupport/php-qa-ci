# Research — Rector's distribution model & why the isolated composer project exists

**Date**: 2026-07-16
**Author**: research (web + local verification)
**Status**: Findings, verified against live package metadata

## The question this answers

Why does php-qa-ci install Rector in an isolated composer sub-project
(`tools/rector/`) instead of just adding it to the PHIVE PHAR set like every
other tool? And is that rationale still valid in 2026?

## Verified facts (live metadata, 2026-07-16)

- **Current Rector**: `rector/rector` **2.5.7** (latest stable).
- **Its `require`** (verified against `tools/rector/composer.lock` AND
  packagist `p2/rector/rector.json`):
  ```json
  "require": {
      "php": "^7.4|^8.0",
      "phpstan/phpstan": "^2.2.2"
  }
  ```
- **Rector is already internally prefixed/scoped.** Since Rector **0.11**
  (Dec 2019 / re-consolidated May 2021), the mainline `rector/rector` package
  ships with its vendor tree prefixed via `humbug/php-scoper` (namespace like
  `RectorPrefix202xxx\Symfony\...`). The old `rector/rector-prefixed` package
  is deprecated — prefixing is now the default and only distribution.
  Sources: getrector.com "Prefixed Rector by Default",
  "How to install Rector despite Composer Conflicts".

## The one dependency Rector deliberately does NOT prefix: phpstan/phpstan

This is the whole story. Rector prefixes Symfony Console, Doctrine, nikic
parser, etc. — **but not `phpstan/phpstan`**. It requires the *real,
unprefixed* `phpstan/phpstan ^2.2.2` because Rector reuses PHPStan's
reflection/type engine and historically could not cleanly prefix it (the
"Cannot redeclare `PHPStan\dumpType()`" class-redeclaration failures when a
scoped PHPStan met a real PHPStan PHAR — rector issues #4606, #2749).

Consequence: **`composer require --dev rector/rector` drags
`phpstan/phpstan ^2.2.2` into the consuming project's dependency graph.**
That is the "phpstan/phpstan leaking into the project's dependencies" the
`rector.inc.bash` header comment refers to. It can conflict with:

- a consumer project that pins its own `phpstan/phpstan` to a different range;
- php-qa-ci's own model, which runs PHPStan **as a committed PHAR**
  (`vendor-phar/phpstan.phar`, `^2.2.3`) precisely so PHPStan is NOT a composer
  dependency of anything.

So the isolated `tools/rector/` composer project is a workaround: keep Rector
(and its unprefixed phpstan) in a **separate composer root** whose `vendor/` is
never merged into the consumer's autoloader.

## Why there is no official Rector PHAR to just drop into phive.xml

Rector **used to** ship a PHAR and **removed it at v0.9**. Per the Rector 0.9
release notes, "The PHAR itself caused over 143 issues so far" — path
resolution, debugging, and Docker/relative-path edge cases. They migrated to
the scoped-composer model and **have not shipped an official PHAR since**.

- Open request for an official PHAR: rectorphp/rector **#5093** (opened by
  szepeviktor, Jan 2021) — never actioned by maintainers.
- Community build **`szepeviktor/rector-phar`** exists but is **dead**: last
  release `0.9.31`, **March 2021**. Not a viable dependency for Rector 2.x.

**Conclusion: if php-qa-ci wants a Rector PHAR, it must build one itself.**

## Why a self-built PHAR fully solves the leak (the key insight)

php-qa-ci already invokes every tool as its **own isolated PHP process**
(`phpNoXdebug -f "$pharDir"/tool.phar`). A PHAR is self-contained: its bundled
dependencies live *inside* the archive and never touch the consumer's composer
autoloader. So a `rector.phar` that bundles its own `phpstan/phpstan`:

1. Removes `phpstan/phpstan` from every composer graph — the leak is gone by
   construction (same reason `phpstan.phar` itself is a PHAR here).
2. Runs in a separate process from `phpstan.phar`, so the historical
   "redeclare `PHPStan\dumpType()`" collision **cannot occur** — the two
   PHPStans never share a process.
3. Makes Rector a peer of the other PHIVE-managed tools — one consistent
   install/update path, no bespoke Phase-2 composer sub-project.

## The concrete pain being removed (verified locally)

- `tools/rector/vendor/` is git-ignored (`.gitignore:22`).
- `tools/rector/composer.json` **and `composer.lock` are tracked** (`git ls-files`).
- `scripts/tool-install.bash` Phase 2 runs, in maintainer `update` mode,
  `composer update --working-dir="$RECTOR_DIR"`, which **rewrites the tracked
  `tools/rector/composer.lock`**. When php-qa-ci is consumed as
  `vendor/lts/php-qa-ci`, that write dirties a tracked file *inside the vendored
  dependency copy* — the exact "makes the php-qa-ci repo dirty in client
  projects" complaint. Even in plain `install` mode, a first-use
  `composer install --working-dir` materialises `vendor/` inside the vendored
  copy.

## Bottom line

The isolated-composer rationale (keep unprefixed phpstan out of the consumer)
is **still technically valid** — Rector 2.5.7 still requires real phpstan — but
a **self-built, committed `rector.phar`** achieves the same isolation *better*:
it removes phpstan from every composer graph, unifies Rector with the existing
PHAR toolchain, and eliminates the tracked-lockfile churn. No official or
maintained community PHAR exists, so building one is the required path.

## Sources

- [How to install Rector despite Composer Conflicts | Rector](https://getrector.com/blog/how-to-install-rector-despite-composer-conflicts)
- [Prefixed Rector by Default | Rector](https://getrector.com/blog/prefixed-rector-by-default)
- [rector/rector-prefixed (deprecated) — Packagist](https://packagist.org/packages/rector/rector-prefixed)
- [Rector-Prefixed not compatible with PHPStan PHAR · rectorphp/rector#4606](https://github.com/rectorphp/rector/issues/4606)
- [Prefixed Rector PHAR not working · rectorphp/rector#2749](https://github.com/rectorphp/rector/issues/2749)
- [Create PHAR with prefixes · rectorphp/rector#177](https://github.com/rectorphp/rector/issues/177)
- [Rector 0.9 Released (PHAR removal, "143 issues") | Rector](https://getrector.com/blog/rector-09-released)
- [PHAR release request · rectorphp/rector#5093](https://github.com/rectorphp/rector/issues/5093)
- [szepeviktor/rector-phar (dead community build, last 2021)](https://github.com/szepeviktor/rector-phar)
- Local: `tools/rector/composer.lock`, `.gitignore`, `scripts/tool-install.bash`, `includes/generic/rector.inc.bash`
