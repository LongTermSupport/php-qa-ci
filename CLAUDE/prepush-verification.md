# Pre-Push Verification (php8.5 = production)

Pushing `php8.5` deploys to production (user ruling 2026-07-15). Branch
protection requires PRs but the maintainer account bypasses it, so the local
battery below is the real gate. CI runs the FULL pipeline in read-only mode
(`qaReadOnly=true`, aggregate) — a pending Rector or PHP CS Fixer change FAILS
the gate, not just test/analysis errors.

## The battery (run against the COMMITTED tree, not a dirty working tree)

Reproduce `QA_READONLY=1 bin/qa` in full, or at minimum:

1. All three Rector configs in dry-run: `rector-safe` (src+tests),
   `rector-phpunit` (tests), `rector-php85` (src+tests — runs whenever the repo
   has no project-level rector.php).
2. `php-cs-fixer` dry-run.
3. PHPStan via the pipeline (`QA_READONLY=1 bin/qa -t stan [-p src]`), NOT the
   bare phar — the bare phar misses the pipeline bootstrap and emits 100+ bogus
   `class.notFound` errors.
4. The test suite.

## Scope gotchas

- When passing explicit file lists to rector/fixer, reproduce the pipeline's
  scope exactly: `qaConfig/qaConfig.inc.bash` sets
  `pathsToIgnore=("tests/assets" "src/PHPUnit/TestDox")`. Including fixture
  assets produces false errors (deliberately-broken ParseError.php etc.).
- CI's rector fragment exits at the FIRST failing config, so a rector-safe
  failure can hide a rector-php85 failure behind it — always dry-run all three.

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
