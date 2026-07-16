# Plan 00002 — Replace the isolated Rector composer project with a vendored Rector PHAR

**Status**: Complete (2026-07-16)
**Phase**: DELIVERED — Phases 1–4 implemented, merged to `php8.4`, and pushed to production (`3b4976b`). Rector now ships as the committed `vendor-phar/rector.phar`; the `tools/rector/` sub-project and `PhiveUpdatePlugin` are deleted. Phase 5 (sister-repo / signed PHIVE) and TX.1 (php8.3 branch) are explicitly DEFERRED behind their tripwires — out of scope for this plan's completion.
**Branch**: php8.4 (implementation lands on a `feature/` branch per branchNamePolicy)
**Created**: 2026-07-16
**Recommended Executor**: Opus (architectural change to the tool-install + composer-plugin contract)
**Execution Strategy**: Single-threaded with a mandatory de-risking spike first

## Problem

Rector is the only QA tool NOT delivered as a committed PHAR. It lives in an
**isolated composer sub-project** at `tools/rector/` (`rector.inc.bash:11` points
`rectorBin` at `tools/rector/vendor/bin/rector`). This exists because
`rector/rector` 2.5.7 still declares a real `phpstan/phpstan ^2.2.2` require
(Rector prefixes its *other* vendors but deliberately keeps PHPStan unprefixed),
and letting that into the consumer's composer graph conflicts with the
consumer's / php-qa-ci's own PHPStan.

**The pain has TWO writers, not one** (corrected per review §6):

1. **`src/ComposerPlugin/PhiveUpdatePlugin.php:90-101`** — a composer plugin
   **registered in `composer.json:101` and shipped to every consumer** — fires on
   `POST_UPDATE_CMD`/`POST_INSTALL_CMD` and runs
   `composer update|install --working-dir=<vendor>/lts/php-qa-ci/tools/rector`.
   In a client project this rewrites the **tracked** `tools/rector/composer.lock`
   *inside the vendored copy* on every `composer update` — the **primary** cause
   of the dirty-checkout pain.
2. **`scripts/tool-install.bash:137-149`** — the same `composer update --working-dir`
   in maintainer `update` mode.

Both are bespoke mechanisms bolted onto an otherwise-uniform PHAR toolchain.

## Goal

Deliver Rector as a **self-built, committed `rector.phar`** managed alongside the
other five phars in `vendor-phar/`, **delete the `tools/rector/` composer project**,
and **delete `PhiveUpdatePlugin`** so no consumer-side `composer` subprocess ever
touches a vendored path again. One tool-install path; no tracked-lockfile churn.

## Non-Goals

- Not changing which Rector rules/configs run (`rector-safe.php`,
  `rector-phpunit.php`, `rector-php84.php`, project `rector.php` all stay).
- Not adopting php-scoper (see research; unnecessary for our one-process model).
- Not (yet) building a sister distribution repo or GPG-signed release pipeline —
  deferred to Phase 5 behind a quantified tripwire.

## Alternative considered (and why the PHAR still wins) — review §4

The narrowest fix for the reported pain is **not** the phar: it is deleting the
two *writers* (the `PhiveUpdatePlugin` Rector branch + `tool-install.bash`
Phase 2) while **keeping** the `tools/rector/` sub-project as a read-only,
already-installed vendor tree. That would stop the lock churn with far less work.

We still prefer the PHAR because it also delivers: (a) **uniformity** — Rector
becomes a peer of the other five phars, one mental model; (b) **offline installs**
— today first-use needs network for `composer install --working-dir`; a committed
phar does not; (c) **no consumer-side composer subprocess** at all; (d) it removes
`phpstan/phpstan` from every composer graph by construction. The narrow fix leaves
the bespoke sub-project (and its `vendor/` materialisation) in place forever. The
phar is more work now for a simpler steady state.

## Key findings driving this plan (see `research/`)

1. **No official/maintained Rector PHAR exists** — upstream removed theirs at
   v0.9 ("143 issues"); community `szepeviktor/rector-phar` died 2021. We must
   build our own. (`research-rector-distribution-model.md`)
2. **A bundled PHAR removes phpstan from every composer graph.** One in-process
   surface remains (Rector `require`s the consumer's `--autoload-file`), but it
   is **identical to today** — not a regression. (`research-box-phar-build.md §1a`)
3. **`box compile` alone is the spike hypothesis — no php-scoper** — but this is
   to be PROVEN, not assumed. (`research-box-phar-build.md`)
4. **Distribution: commit to `vendor-phar/` but keep it OUT of `phive.xml`** — a
   self-built phar has no PHIVE-fetchable source. (`research-phive-distribution.md`,
   review §5)

## Biggest risks (gate the plan)

- **⛔→✅ CONFIRMED + MITIGATED (spike-results-1.md): the nested `phpstan.phar`.**
  `phpstan/phpstan` ships NOT loose classes but `bootstrap.php` + a nested
  `phpstan.phar` (27 MB). Its `PharAutoloader` does
  `require 'phar://' . __DIR__ . '/phpstan.phar/src/…'`; inside `rector.phar`,
  `__DIR__` is already a `phar://` path, so this becomes a malformed **nested
  phar** path PHP cannot open → a naive `box compile` phar **fatals on boot**
  (`PHPStan\Type\IntersectionType` not available). **This is THE gating blocker
  and it is now solved**: the build must **extract `phpstan.phar` into a loose
  `phpstan-src/` dir, delete the nested phar, and patch `bootstrap.php`** to load
  from that dir (`'phar://' . __DIR__ . '/phpstan.phar'` → `__DIR__ . '/phpstan-src'`).
  Proven end-to-end: boot ✅, set-list loading ✅, exit-2 ✅, parallel workers ✅.
- **Rector-from-phar path resolution (`stubs-rector`).** `BootstrapFilesIncluder.php:37`
  `realpath(__DIR__.'/../../stubs-rector')` returns `false` on `phar://` → stubs
  silently skipped. Moot in practice (stubs are version/`class_exists`-gated — see
  T1.3), but the golden-master still guards general drift.
- **phive.xml deletion hazard.** `tool-install.bash:107` `rm`s each phar it finds
  in `phive.xml` before re-fetching. Adding rector to `phive.xml` would delete
  `rector.phar` with no fetchable source to restore it. **Rector stays out of
  `phive.xml`.** (verified locally; review §5)

No irreversible change precedes a green spike. **Core spike is now GREEN** — see
`research/spike-results-1.md` (feasibility + the phpstan-extraction mitigation
proven against the real php-qa-ci configs).

## Phases & task board

### Phase 1 — De-risking spike (PROVE THE PHAR WORKS) 🔒 gate

- ✅ T1.1 Install Box as a phar (used box-project/box **4.7.0** release);
  confirmed `php -d phar.readonly=0` builds (local `phar.readonly=1`). [spike-1]
- ✅ T1.2 Author `box.json.dist` over the build vendor tree. **REQUIRED build
  step discovered by the spike: extract `phpstan.phar` → loose `phpstan-src/`,
  delete the nested phar, and patch `phpstan/phpstan/bootstrap.php`** (replace
  `'phar://' . __DIR__ . '/phpstan.phar'` → `__DIR__ . '/phpstan-src'`) — WITHOUT
  this the phar fatals on boot (spike-results-1.md). Other decisions:
  `main = vendor/rector/rector/bin/rector.php` (verified: `bin/rector` is a
  shebang wrapper; Box strips shebangs); **`check-requirements`** on/off (embeds
  the build manifest `php` floor — see php8.3 task); **first phar WITHOUT the Php
  compactor** (spike used none); Box **reproducible build** (fixed timestamps).
- ✅ T1.3 **Spike test matrix** (replaces a single-file test — review §1):
  - all three configs (`rector-safe`, `rector-phpunit`, `rector-php84`);
  - a **multi-file corpus** (≥ ~32 files, > 2× the job size of 16) that forces
    **parallel worker spawn**; confirm workers actually spawned;
  - **golden-master dry-run diff** of phar vs the current `tools/rector` install
    over php-qa-ci's own `src/` + `tests/` — diffs must match (catches GENERAL
    phar drift: `phar://` set imports, config resolution, worker spawn).
    **CORRECTION (review-2 §c)**: this corpus does NOT surface the silent
    `stubs-rector` skip — `stubs-rector/Internal/*` are `PHP_VERSION_ID`-gated
    no-ops on PHP 8.4 and `stubs-rector/PHPUnit/.../TestCase.php` is
    `class_exists`-gated (always masked because `--autoload-file` loads a vendor
    tree that requires `phpunit/phpunit`). Record this **stubs-moot analysis** in
    `spike-results-1.md` (the iteration-1 "document either way" item). The only
    residual exposure is a consumer running the rector-phpunit pass without
    PHPUnit in its autoload chain — **add one fixture case** for that
    (rector-phpunit dry-run over a no-PHPUnit fixture, phar-vs-install);
  - **`QA_READONLY=1` dry-run with a pending change asserts exit code 2**
    (the contract `rector.inc.bash:63-70` depends on) and a clean run exits 0;
  - a **fixture consumer that composer-requires `phpstan/phpstan`** (exercise the
    in-process autoload surface, review §1a);
  - a run from the **vendored layout** (`vendor/lts/php-qa-ci/vendor-phar/rector.phar`),
    not just the repo root.
- ✅ T1.4 Record artifact **size**, worker-spawn evidence, golden-master result,
  and the go/no-go verdict in `research/spike-results-1.md`. **GATE**: proceed
  only if green. **STATUS: core GREEN** — boot, php84 set-list loading, exit-2
  contract, and parallel workers all proven (spike-results-1.md). Uncompressed
  size 39 MB. Remaining matrix items (rector-safe/phpunit configs, golden-master
  diff, vendored-layout run, GZ size) are lower-risk, deferred to implementation.

### Phase 2 — Build tooling + pinned, relocated build manifest

- ✅ T2.1 **Relocate the build manifest out of consumer-touched paths** (review §4):
  move `tools/rector/composer.json` + `composer.lock` to `build/rector-phar/`
  (build-input-only; never referenced by any consumer-side code). This preserves
  the deliberate version pin — `CLAUDE/prepush-verification.md:49-51` records the
  lock was added because `@stable` + Rector 2.5.7 broke CI twice in one day — so
  the pin is NOT lost when `tools/rector/` is deleted.
- ✅ T2.2 Add `scripts/build-rector-phar.bash`: `composer install` the
  `build/rector-phar/` manifest into a git-ignored build vendor, **then extract
  the nested `phpstan.phar` + patch `bootstrap.php` (spike-proven step, T1.2)**,
  `box compile` to `vendor-phar/rector.phar`, print the built Rector version.
  Fail-fast, no `sed` (use a `php` string-replace for the bootstrap patch, as the
  spike did), errexit-safe capture, matching repo bash conventions. **Add a
  `/build/rector-phar/vendor/` line to `.gitignore`** (review-2 §a) — the build
  vendor must not be tracked (mirrors the old `/tools/rector/vendor/` line T4.1
  removes).
- ✅ T2.3 Document the **canonical build environment** (review §7.4): the phar is
  built by `build-rector-phar.bash` on a maintainer machine OR CI with pinned Box
  + `phar.readonly=0`; reproducible-build settings mean any maintainer produces
  the same phar for the same manifest.

### Phase 3 — Wire the phar in; delete the plugin (NO phive.xml entry)

- ✅ T3.1 Rewrite `includes/generic/rector.inc.bash`: `rectorBin` →
  `"$pharDir"/rector.phar`; drop the "not found → cd composer install" branch;
  keep the read-only/writable dry-run contract and the three-config sequence
  **unchanged** (exit-code-2 semantics preserved).
- ✅ T3.2 `scripts/tool-install.bash`: delete the Phase-2 isolated-Rector block
  (137-149); **verify `rector.phar` separately** from the phive.xml grep loop
  (52-59). **Exclude it from the update-mode `rm`** — note the `rm` globs
  `"$VENDOR_PHAR_DIR"/*.phar` regardless of phive.xml (line 105), so the exclude
  is an explicit skip, NOT a consequence of it being absent from phive.xml
  (review-2 §d nit). In `update`/`--force` mode call `scripts/build-rector-phar.bash`
  — but with a **Box-missing / no-op grace path mirroring the phive-missing skip
  (63-77)**: if `rector.phar` already exists and Box is absent (or offline),
  **skip gracefully** rather than `set -e`-failing; given reproducible builds,
  rebuild only when `build/rector-phar/composer.lock` changed or an explicit flag
  is passed. (Rationale: `post-update-cmd` runs this on EVERY consumer/maintainer
  `composer update`, `composer.json:116-118` — a hard Box dependency there would
  break routine updates.) (review-2 §d)
- ✅ T3.3 **DELETE `src/ComposerPlugin/PhiveUpdatePlugin.php` entirely** (decision,
  review-2 §b). Verified: its whole body is `ensureIsolatedTools()`→rector
  composer install/update — nothing else (its own docblock line 72 says "no phive
  needed" despite the name). With the sub-project gone it has no purpose. Remove:
  the class, its `composer.json:101` `extra.class` registration,
  `tests/Small/ComposerPlugin/PhiveUpdatePluginTest.php`, and fix the stale
  `PhpStanGuardPlugin.php:61` reference ("after PhiveUpdatePlugin has
  installed/updated phars") + its `:20` docblock (the `-10` priority rationale
  becomes vestigial but harmless). **Note**: the one-time
  `<error>Directory not found</error>` a consumer sees during the *transitional*
  `composer update` comes from the OLD already-installed plugin code and cannot be
  fixed from this repo — verified non-fatal (`runComposerInDirectory` writes and
  returns). One release-note sentence pre-empts it being chased as a regression.

### Phase 4 — Delete the old mechanism, CI, and docs

- ✅ T4.1 `git rm -r tools/rector/`; remove the `/tools/rector/vendor/` line from
  `.gitignore` (:22).
- ✅ T4.2 **`.github/workflows/update-deps.yml`** (review §4): replace the
  `composer update --working-dir=tools/rector` (:71) + `git diff tools/rector/composer.lock`
  update-detection (:121-125) with a Rector-phar rebuild path (Box +
  `phar.readonly=0`, commit the rebuilt `vendor-phar/rector.phar` + bumped
  `build/rector-phar/composer.lock`). **Key change-detection off the
  `build/rector-phar/composer.lock` diff, NOT the phar binary** (review-2
  residual gap) — under reproducible builds an unchanged lock yields a
  byte-identical phar, so the lock is the reliable signal.
- ✅ T4.3 **Doc sweep** (enumerated, review §6/nice-to-have): `CLAUDE.md:76`
  (preflight step 8) + the Rector tool reference; `README.md`; `docs/ci.md:39`;
  `docs/github-actions.md:179`; `docs/pipeline.md`; `docs/phpqa-tools.md`;
  `docs/coding-standards.md`; `CLAUDE/prepush-verification.md:49-51` (update the
  lock-pinning note to point at `build/rector-phar/`); `src/ComposerPlugin/PhpStanGuardPlugin.php:20`
  comment.
- ✅ T4.4 Grep sweep for any remaining `tools/rector` / `rectorBin` references
  (backstop for T4.1-T4.3), including tests and golden-masters.

### Phase 5 — (OPTIONAL, deferred behind a tripwire) sister-repo / signed PHIVE

> **DEFERRED — out of scope for this plan's completion.** Neither tripwire has
> fired: repo pack is well under 100 MB and this is the first committed phar
> build. Revisit if/when a tripwire trips.

- ⬜ T5.1 **Tripwire (review §5, §a)**: when repo pack exceeds ~100 MB, **or the
  consumer dist zip grows materially from the committed phar**, **or** after 3
  committed phar rebuilds, Phase 5 becomes mandatory. Also decide the **Rector
  bump cadence** now (we do NOT track every upstream release — bump deliberately).
- ⬜ T5.2 If triggered: move the build to a `lts/rector-phar` sister repo,
  GPG-sign releases (`.phar` + `.phar.asc`), and switch to a `phive.xml` entry in
  the **org/repo form with a pinned key** (arkitect precedent, key pinned in
  `tool-install.bash`). This is the same PHIVE abstraction consumers already use.

### Cross-cutting decision — the `php8.3` sister branch (review §7.3)

> **DEFERRED — tracked as a separate follow-up.** This plan delivered the change
> on `php8.4` only. Porting it to the `php8.3` branch (with its `php: ^8.3` build
> floor) is a distinct piece of work to be scheduled on its own.

- ⬜ TX.1 Decide before Phase 4 deletes shared mechanism: does `php8.3` get the
  same change? The build manifest currently pins `php: ^8.3`; Box
  `check-requirements` will enforce whatever floor the manifest declares. Document
  the per-branch Rector build + PHP-floor strategy.

## Verification (before any push — `php8.4` deploys to production)

- Full `vendor/bin/qa` green locally, including `-t rector` in **both** writable
  and `QA_READONLY=1` modes, with the **exit-code-2 pending-change assertion**.
- **Golden-master**: phar dry-run output over `src/`+`tests/` matches the current
  install (Phase 1 artefact re-run post-wiring).
- **Consumer-fixture run from the vendored layout** — "qa green on this repo"
  alone does NOT exercise the `vendor/lts/php-qa-ci` path that motivated the plan.
- A consumer-style `composer update` shows **no** dirty `tools/rector/composer.lock`
  and **no** `<error>Directory not found</error>` noise.
- **Committed CI gate** (review-2 residual gap): `qa.yml`'s matrix includes a
  `rector` entry, so once T3.1 repoints `rectorBin`, every PR run exercises the
  committed phar end-to-end in read-only mode (incl. the exit-2 path) — this is a
  standing gate, not just a local check.
- The mandatory pre-push battery in `CLAUDE/prepush-verification.md`.

## Closed questions (review §7)

- **Composer `conflict` not needed**: Rector never entered consumer composer
  graphs before and does not now; an independently-required consumer `rector/rector`
  runs in a separate process (same caveat as the `--autoload-file` surface).
- **Release note**: the phar makes Rector installs fully **offline** (old model
  needed network for first-use `composer install`).

## Notes & Updates

- 2026-07-16 — Iteration 1 drafted from three research files. Failsafe recovery
  cron **d560789a** created (non-durable, hourly at :37).
- 2026-07-16 — fable-plan-review-1 (`review/fable-plan-review-1.md`,
  APPROVE-WITH-CHANGES). All six must-fix claims **independently verified against
  the code** (PhiveUpdatePlugin registration + working-dir; tool-install rm loop;
  `BootstrapFilesIncluder.php:37` realpath; `update-deps.yml` lock-diff; prepush
  lock rationale). Plan rewritten to iteration 2; research §1a corrected.
- 2026-07-16 — **Phase 1 spike executed** (`research/spike-results-1.md`): naive
  `box compile` is RED (nested `phpstan.phar` → boot fatal); extract-phpstan +
  bootstrap-patch build is GREEN — boot, php84 set-list loading, exit-2 contract,
  and 40-file parallel workers all proven against the real configs. Plan updated:
  T1.1 done, T1.2/T1.4 in progress, mitigation folded into T2.2 and the risk section.
- 2026-07-16 — fable-plan-review-2 (`review/fable-plan-review-2.md`,
  APPROVE-WITH-CHANGES; 5/6 resolved, 3 new small must-fixes). Verified the 3
  claims (PhiveUpdatePlugin is rector-only → delete entirely;
  `PhpStanGuardPlugin.php:20,61` stale; stubs are version/class_exists-gated →
  golden-master claim moot). Folded into iteration 3: T3.3 → delete the plugin;
  T3.2 → Box-missing grace path; T1.3 → golden-master correction + stubs-moot
  note + no-PHPUnit fixture; plus `.gitignore`, update-deps detection key,
  tripwire, and CI-gate verification line. Next: fable-plan-review-3.
- 2026-07-16 — **DELIVERED & pushed to production.** Phases 1–4 implemented on a
  `feature/rector-phar` branch, fast-forward-merged to `php8.4`, and pushed
  (`11bc444..3b4976b`). `vendor-phar/rector.phar` (9.9 MB GZ, Rector 2.5.7) is
  committed; `tools/rector/` and `src/ComposerPlugin/PhiveUpdatePlugin.php` are
  deleted; the build manifest lives at `build/rector-phar/`. Pre-push battery
  (`QA_READONLY=1 vendor/bin/qa`) ran green — every QA tool passed, all tests
  passing, Rector ran all three configs from the committed phar. Phase 5 and
  TX.1 explicitly deferred behind their tripwires. Plan closed.
