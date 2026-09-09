# How to run the pipeline, and the pre-push gate

**This file is the single source of truth for which QA command to run and when.** Skills,
agent instructions and other docs point here; they must not restate the commands, because a
duplicated instruction is one that can be wrong in several places at once — which is exactly
how `QA_READONLY=1` came to be run as a default by more than one agent.
Pushing `php8.5` deploys to production (user ruling 2026-07-15). Branch
protection requires PRs but the maintainer account bypasses it, so the local
battery below is the real gate. CI runs the FULL pipeline in read-only mode
(`QA_READONLY=1`, aggregate) — a pending Rector or PHP CS Fixer change FAILS
the gate, not just test/analysis errors. **That is a fact about CI, not an
instruction for you**; see below.

## Work writable. That is the default, and it is what you want.

```bash
bin/qa
```

**Do not prefix `QA_READONLY=1` while working.** Writable is the default, and in a
writable run Rector and PHP CS Fixer *apply* their changes, so you are left with
the findings that actually need a person. A read-only run turns "the fixer would
reformat one file" into a failure you then repair by hand, for no reason. Commit
what the fixers applied and move on.

`QA_READONLY=1` has exactly one use: reproducing a CI failure you cannot explain.
It is not a stricter check and it verifies nothing extra — the same lanes run with
the same rules; the only difference is that the fixers report instead of fixing.

While iterating, `bin/qa -t <tool>` runs one lane, and `bin/qa -t allStatic` /
`-t allLints` / `-t allCS` / `-t allTests` run a phase. Use those for speed and the
full `bin/qa` at checkpoints.

`bin/qa` takes the project's run lock; if it reports another QA run holding the
lock, wait for that run to finish (the lock goes stale after ten minutes of
inactivity) rather than deleting the lock file.

## The gate, run once, against the COMMITTED tree

After the writable run is clean and everything is committed, confirm CI will agree:

```bash
QA_READONLY=1 CI=true bin/qa
```

This must pass with nothing pending. If it fails where the writable run passed,
you have uncommitted fixer output — commit it and re-run. This is the only place
in the workflow where read-only belongs.

Lane-specific notes:

1. The Rector lane runs three configs — `rector-safe` (src+tests),
   `rector-phpunit` (tests), `rector-php85` (src+tests, whenever the repo has no
   project-level rector.php) — and **stops at the first failing one**, so a
   rector-safe failure can hide a rector-php85 failure behind it.
2. PHPStan must go through the pipeline (`bin/qa -t stan [-p src]`), NOT the bare
   phar — the bare phar misses the pipeline bootstrap and emits 100+ bogus
   `class.notFound` errors.

## Scope gotchas

- When passing explicit file lists to rector/fixer, reproduce the pipeline's
  scope exactly: `qaConfig/qa.php` declares
  `->withIgnoredPaths('tests/assets', 'src/PHPUnit/TestDox')`. Including fixture
  assets produces false errors (deliberately-broken ParseError.php etc.).
- The Rector lane stops at the FIRST failing config, so a rector-safe failure
  can hide a rector-php85 failure behind it — re-run after each fix until it is
  clean, rather than assuming one pass covered all three.

## The Safe-conversion ripple (rector-safe)

Converting calls to `\Safe\*` changes types, and the fixes must land together
(iterate to fixpoint across rector → fixer → phpstan → phpunit):

- `assertNotFalse` guards on Safe results become tautological
  (`alreadyNarrowedType`) — DELETE them; the throwing function absorbed the
  guarantee.
- Safe stubs return `mixed` / by-ref `array|null` — add real runtime narrowing
  (`assertIsString` / `assertIsArray` / `assertArrayHasKey`), not PHPDoc
  overrides.
- `Safe\mkdir` / `Safe\chmod` return VOID: any `mkdir(...) || fail()` or
  `assertTrue(chmod(...))` pattern is BROKEN by the conversion (always-fail) —
  rewrite as a bare statement and re-run the affected tests to prove runtime
  behaviour.

## Rector is version-pinned

`build/rector-phar/composer.lock` is tracked in git (relocated from the former
`tools/rector/` sub-project; the lock has been tracked since 1118447 — before
that it was gitignored, CI floated `rector/rector: @stable`, and Rector 2.5.7's
release broke CI twice in one day with zero repo changes). Bump deliberately via
tool-install's update mode (or `scripts/build-rector-phar.bash`) and commit the
updated lock together with the rebuilt `vendor-phar/rector.phar`.
