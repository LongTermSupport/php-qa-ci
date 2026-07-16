# Fable Plan Review — 00002 (iteration 1)

> Reviewer: Fable-model sub-agent (Plan/architect persona), 2026-07-16.
> Saved verbatim by the coordinator (daemon policy blocks subagent report-writes
> into plan folders). Verdict: **APPROVE-WITH-CHANGES**.

**Verdict: APPROVE-WITH-CHANGES.** The problem is real, the phased shape (spike-gated, delete-last) is right, and the research is unusually well-verified — but the plan has one factual blind spot big enough to sink the stated goal, and one architectural contradiction in the wiring. First, the plan never mentions `src/ComposerPlugin/PhiveUpdatePlugin.php`, the composer plugin that runs `composer update --working-dir=vendor/lts/php-qa-ci/tools/rector` on every consumer `composer update` — this, not just `scripts/tool-install.bash`, is the mechanism actually dirtying client checkouts, and deleting `tools/rector/` without reworking it leaves error spew in every consumer install. Second, the premise "add rector to `phive.xml` exactly like the other 5 phars" is architecturally wrong: the other five have PHIVE-fetchable upstream sources; a self-built phar has none, and `tool-install.bash` update mode `rm`s every `vendor-phar/*.phar` before running `phive install` — which would delete `rector.phar` with no way to restore it. Third, code-level inspection of Rector 2.5.7 shows the "box compile, no scoper" claim is *probably* workable but the spike as scoped (one tmp file) would produce a false green: it exercises neither parallel worker spawning nor the `stubs-rector` loader, both of which have concrete phar-sensitivity in the shipped source. All fixable within the existing phase structure.

---

## 1. Feasibility — the "no php-scoper" claim and what the spike must actually test

The core claim (research-box-phar-build.md, "Do we need php-scoper — NO") is **directionally right but overstated in two ways**, and the spike as written (T1.3) would not catch the failure modes that matter.

**1a. "No collision surface" is false — `--autoload-file` brings the consumer in-process.** `rector.inc.bash:45` passes `--autoload-file "$projectRoot/vendor/autoload.php"`, which Rector `require`s into the phar's process (`bin/rector.php`, `autoloadFromCommandLine()`). If a consumer composer-requires `phpstan/phpstan` (or anything sharing unprefixed symbols with the phar's bundled phpstan), the two coexist in one process — exactly the surface behind rector#4606's "Cannot redeclare `PHPStan\dumpType()`". The saving grace is that **this is identical to today's `tools/rector/` setup** (unprefixed phpstan in Rector's vendor + consumer autoloader loaded on top), so it is not a *regression* — but the research's "collision structurally impossible" framing is wrong and should be corrected so nobody later relies on it. **Mitigation**: add a spike case running the phar in a fixture project that composer-requires `phpstan/phpstan`.

**1b. Verified phar-specific breakage in Rector 2.5.7 source — silent, not crashing.** `src/Autoloading/BootstrapFilesIncluder.php:37`:

```php
$stubsRectorDirectory = realpath(__DIR__ . '/../../stubs-rector');
if ($stubsRectorDirectory === \false) {
    return;
}
```

`realpath()` returns `false` on `phar://` paths, so **running from a phar silently skips loading `stubs-rector/Internal` and `stubs-rector/PHPUnit`**. No crash — a trivial smoke test passes — but rule behavior can differ on code referencing stubbed classes (the PHPUnit stubs are directly relevant to the `rector-phpunit.php` pass). This is exactly the "143 issues" class of bug, and it is **invisible to T1.3 as written**. **Mitigation**: the spike must include a golden-master diff — run the current `tools/rector` install and the phar over the same non-trivial corpus (php-qa-ci's own `src/` + `tests/` is ideal) in `--dry-run` and diff the reported diffs. Also assess whether `--autoload-file` loading the consumer's real PHPUnit makes the stubs moot in our usage; document either way.

**1c. Parallel workers — plausible but untested by a one-file spike.** All three shipped configs enable parallel (`configDefaults/generic/rector-*.php`, `$rectorConfig->parallel(120, N, 16)`). `ApplicationFileProcessor::resolveCalledRectorBinary()` returns `$_SERVER['argv'][0]` if `file_exists()` — with `phpNoXdebug -f "$pharDir"/rector.phar`, argv[0] is the on-disk phar path, so workers respawn as `PHP_BINARY /path/rector.phar process … worker …`, which requires the Box stub to execute correctly when invoked as a plain script. Probably fine with Box's default stub — **but a single tmp file never crosses the parallel job-size threshold, so T1.3 would never spawn a worker**. **Mitigation**: run the spike over a directory with enough files (≥ ~2× job size of 16) and confirm worker processes actually spawned and returned results.

**1d. Autoload bootstrap inside the phar — incidental, verify it.** `bin/rector.php` `loadIfExistsAndNotLoadedYet()` (line ~92) calls `realpath($filePath)` for dedup bookkeeping but still `require_once $filePath` — so the phar-internal `vendor/autoload.php` (reached via `autoloadProjectAutoloaderFile()`'s `__DIR__ . '/../../../autoload.php'` = `phar://…/vendor/autoload.php`) loads despite `realpath` returning `false`. It works by accident, not design; the dedup array accumulates `false`. Low severity, but pin it in the spike rather than assuming.

**1e. phpstan-inside-rector concern**: Rector consumes PHPStan as a library (its reflection engine), not via the consumer's `phpstan.neon`, and never loads php-qa-ci's phpstan extensions — so there is no phpstan-extension-in-phar interaction with the pipeline's `phpstan.phar` config. The bundled phpstan code is the same code upstream ships in its own (working) phar, so phar-tolerance is plausible. No action beyond the golden-master diff.

**Spike checklist the plan should adopt verbatim (replaces T1.3's single-file test)**: all three configs; multi-file corpus forcing parallel; golden-master diff vs the current install; `QA_READONLY` dry-run with a pending change asserting **exit code 2** (the contract `rector.inc.bash:37,63-70` depends on); a fixture consumer that composer-requires phpstan; a run from the vendored layout (`vendor/lts/php-qa-ci/vendor-phar/rector.phar`), not just the repo root.

## 2. box.json entry point

`vendor/rector/rector/bin/rector.php` is the **correct** main. Verified locally: `vendor/bin/rector` → `bin/rector` is a 4-line shebang wrapper that just `require_once __DIR__ . '/rector.php'`; Box strips shebangs from `main` anyway, so pointing at `rector.php` directly is right. `rector.php` sets `memory_limit=-1`, `gc_disable()`, and `__RECTOR_RUNNING__` itself — nothing a stub breaks, and `phpNoXdebug`'s memory-limit handling is overridden by Rector's own `ini_set` exactly as today.

Gaps in the research's recipe (research-box-phar-build.md §"Concrete build recipe"):

- **Box's `check-requirements` defaults to true**, injecting a requirements checker built from `tools/rector/composer.json`/lock — which pins `php: ^8.3` and any bundled ext requirements. Decide this intentionally (it affects the php8.3-branch question, §7).
- **`compression: GZ` requires ext-zlib at runtime** (stub-enforced). Universal in practice, but it also destroys git delta compression of the committed blob (§5).
- **The Php compactor strips comments** from bundled code. Rector's runtime behavior on *user* code is docblock-parsing of on-disk files (unaffected), but consider building the first shipped phar **without** the compactor to reduce spike variables, then adding it once green.
- **Reproducibility**: Box embeds timestamps by default; rebuilding identical inputs yields a different phar → spurious churn on every maintainer run. Configure Box's reproducible-build settings or accept and document.

## 3. Config cascade interaction — mostly a non-landmine, with two real checks

Good news the plan can state affirmatively: `configPath` (`includes/functions.inc.bash:74-86`) returns an **on-disk** path (project `qaConfig/`, platform, or `configDefaults/generic/`), and the config file is passed by absolute path via `--config`. The config is never inside the phar, so `__DIR__` in `rector-safe.php:70-77` still resolves on disk, and the `__DIR__ . '/../../../../thecodingmachine/safe/rector-migrate.php'` walk-out to the consumer's vendor is **unchanged by the phar**. Same for the `$_SERVER['PWD']` composer.json preflight and the `rectorIgnorePaths` env pass-through. This suspected landmine is not one.

The two real interactions:

1. **Set imports resolve to `phar://` paths.** `rector-php84.php` uses `LevelSetList::UP_TO_PHP_84` etc. — constants that are `__DIR__`-relative paths *inside the phar*. Rector must `require`/import `phar://…/config/set/…` files; any `realpath()` in that import path fails. This is covered only if the spike runs the php84 config (T1.3 does — keep it mandatory) over real files.
2. **`rector-migrate.php` from the consumer's `thecodingmachine/safe` references unprefixed core `Rector\…` classes**, resolved by the phar's autoloader. Core Rector classes are unprefixed so names match; API-version skew between the consumer's safe package and the phar's Rector is the same risk as today — no regression, but worth one sentence in the plan.

Also make explicit in verification: the read-only path (`rector.inc.bash:63-70`) distinguishes **exit code 2** (pending diff → `reportReadOnlyWouldModify`) from other non-zero (genuine error → exit 1). The plan's "keep the contract unchanged" (T3.2) needs a test that a phar dry-run with a pending change actually exits 2, not merely that a clean run exits 0.

## 4. Version / update workflow — the reproducibility regression

- `tools/rector/composer.json:7` requires `"rector/rector": "@stable"`; the **only** pin is `composer.lock` (2.5.7, including the transitive `phpstan/phpstan` version). T4.1 deletes both while T2.2 leaves the build-dir strategy "undecided". After that, **nothing is a build input that pins what goes into the phar** — `phive.xml installed="…"` is documentation, not an input. Worse, `CLAUDE/prepush-verification.md:49-51` records that the lock was introduced deliberately *because* `@stable` broke CI twice in one day. Deleting the lock without an equivalent pin re-opens that wound.
- **Recommended resolution for T2.2** (the plan should decide, not defer): keep a tracked build manifest — either retain `tools/rector/composer.json` + `composer.lock` as *build-input-only* (renamed, e.g. `build/rector-phar/`, so no consumer-side code ever touches it), or have `build-rector-phar.bash` take an explicit `rector/rector:X.Y.Z` argument and write it into `phive.xml installed=` and the commit message. Note the tracked-lock option also reveals a **cheaper alternative to the whole plan** worth one paragraph of due diligence: the pain is not the lock's *existence* but that consumer-side automation *rewrites* it — deleting the two writers (tool-install Phase 2 + the plugin, §6) while keeping the sub-project would also fix the reported pain, at the cost of keeping the bespoke mechanism. The plan should say why the phar is still preferred (uniformity, offline install, no consumer-side composer subprocess) rather than leaving the smaller fix unexamined.
- **`.github/workflows/update-deps.yml:71,121-125`** runs `composer update --working-dir=tools/rector` and diffs `tools/rector/composer.lock` to detect Rector updates. The plan never mentions this workflow. Its replacement is non-trivial: rebuilding the phar in CI needs Box + `phar.readonly=0` + committing a ~25-30 MB binary from an Action. That deserves its own task, not a hope that T4.3's grep catches it.

## 5. Distribution choice — the phive.xml contradiction and git-bloat math

**The wiring as planned is self-breaking.** `scripts/tool-install.bash:102-110` (update mode) deletes **every** `vendor-phar/*.phar`, then runs `phive install` from `phive.xml`. PHIVE has no concept of a "local-only" phar: every `<phar>` entry needs a resolvable source (alias or org/repo release with a `.phar` + `.asc`). Rector has neither — upstream shipped no phar since 0.9 and there is no sister repo yet (explicitly deferred, T5.1). So after T3.1 adds rector to `phive.xml`: a maintainer `composer update` in this repo (post-update-cmd → `tool-install.bash update`, `composer.json:112-118`) **deletes rector.phar and cannot restore it**, and the unresolvable entry may fail the entire `phive install` for the other five tools. T3.1's parenthetical ("local-location entry only, like the others") rests on a false premise — the others all have fetchable sources; `location=` is where PHIVE *puts* them, not where it *gets* them.

**Required design change (Phase 3)**: keep `rector.phar` in `vendor-phar/` but **out of `phive.xml`**. Teach `tool-install.bash` to verify it separately from the phive.xml-grep loop (Phase 1, lines 52-59), exclude it from the update-mode `rm` glob, and have update mode call `build-rector-phar.bash` instead of phive for this one tool. Revisit the phive.xml entry only when Phase 5's sister repo provides a real fetchable source.

**Repo bloat**: current pack is 18.22 MiB total. A GZ-compressed phar does not delta in git — each Rector bump adds a near-full-size blob. At Rector's roughly monthly release cadence, that is on the order of +250-350 MB of permanent history per year if you track upstream, and the composer dist zip every consumer downloads grows by the phar size forever. Committing first is still a defensible day-one pain-killer (the phpstan.phar precedent exists, and Option 1's "zero new consumer mechanics" argument is sound), **but only with an explicit tripwire recorded in the plan** — e.g. "after 3 committed rebuilds or repo pack > 100 MB, Phase 5 becomes mandatory" — plus an explicit decision on update cadence (you do *not* have to track every upstream release). Without that tripwire, "defer the sister repo" quietly becomes "never", and the migration cost only grows because stranded blobs stay in history.

## 6. Deletion safety & tests

**The plan's biggest omission: `src/ComposerPlugin/PhiveUpdatePlugin.php:90-101`.** This plugin (registered in `composer.json` `extra.class`, shipped to and activated in every consumer) fires on `POST_UPDATE_CMD`/`POST_INSTALL_CMD` and runs `composer update|install --working-dir=$vendorDir/lts/php-qa-ci/tools/rector`. In a client project, **this is the code that rewrites the tracked lock inside the vendored copy on every `composer update`** — the plan's problem statement (PLAN.md:19-24) attributes the pain solely to `scripts/tool-install.bash`, which is at best half the story. Consequences for the plan:

- Phase 3/4 must add: strip the Rector logic from `PhiveUpdatePlugin` (or make missing-`tools/rector` a *silent* skip — today `runComposerInDirectory()` emits `<error>Directory not found…</error>` on every consumer composer event once the dir is gone; non-fatal, but permanent noise in every consumer install).
- `tests/Small/ComposerPlugin/PhiveUpdatePluginTest.php` must be updated in the same commit — the plan's T4.3 grep would eventually find it, but a plugin shipped to every consumer deserves a named task, not a grep catch.
- `src/ComposerPlugin/PhpStanGuardPlugin.php:20` has a docblock referencing the isolated sub-project (comment-only).

**Other references T4.2/T4.3 should enumerate explicitly** (all verified present): `.github/workflows/update-deps.yml` (§4), `docs/ci.md:39`, `docs/github-actions.md:179`, `docs/pipeline.md`, `docs/phpqa-tools.md`, `docs/coding-standards.md`, `CLAUDE.md:76` (preflight step 8 describes the tools/rector install), `CLAUDE/prepush-verification.md:49-51` (the lock-pinning rationale — update, don't just delete), `.gitignore:22`, `README.md`.

**Phase ordering**: the spike-gates-deletion shape is correct and nothing is deleted too early *between* phases — but *within* Phase 3, T3.1+T3.3 as written introduce the delete-and-can't-restore hazard (§5) before Phase 4 even starts. Fix the phive.xml design before wiring. Mid-upgrade consumer compat is acceptable: composer replaces the whole `vendor/lts/php-qa-ci` package dir on update (wiping the untracked materialized `tools/rector/vendor/`), and the plugin event that fires during the upgrading update must tolerate the dir's absence — hence the silent-skip requirement above. `ToolRegistryCharacterisationTest` only maps tool names and is unaffected; `qa.yml`'s `rector` matrix entry keeps working since the phar is committed (and gains offline capability — worth a release-note line).

## 7. Missing tasks / blind spots (beyond the above)

1. **PhiveUpdatePlugin + its test** — §6, must be named tasks.
2. **`update-deps.yml` rework** — §4.
3. **The `php8.3` sister branch** (`CLAUDE.md:358`): does it get the same change? If yes, that's a second committed ~25-30 MB phar on another branch (git stores blobs once per content, but the branches will drift to different Rector builds) and the phar's PHP floor matters — the build root currently pins `php: ^8.3`, and Box's requirements checker will enforce whatever the build manifest says. One task: decide and document the php8.3-branch strategy before Phase 4 deletes shared mechanism.
4. **Maintainer build environment**: T1.1 verifies local `phar.readonly` handling, but there is no statement of where the *canonical* build happens (any maintainer's machine? CI only?) — reproducibility (§2, §4) makes this matter; two maintainers should not produce different phars for the same Rector version.
5. **Composer `conflict`**: not needed — Rector never entered consumer composer graphs under the old model either, and the phar doesn't change that. If a consumer independently requires `rector/rector`, the two don't interact (separate processes/autoloaders — same caveat as §1a). One sentence in the plan closes this question.
6. **Verification section** (PLAN.md:124-131) should add: the golden-master old-vs-new output diff (§1b), the explicit exit-code-2 assertion (§3), and a consumer-fixture run from the vendored layout — "full qa green locally" on this repo alone does not exercise the `vendor/lts/php-qa-ci` path that motivated the whole plan.

## 8. Overall verdict

**APPROVE-WITH-CHANGES.** The direction is right and the gating spike is the correct instinct; the research is strong on *why* and thin on *how the repo's own machinery interacts with the change*. Nothing here requires abandoning the approach, but items 1-4 below would each cause a real incident if implemented as written.

## Must-fix before implementation

- [ ] **Add `src/ComposerPlugin/PhiveUpdatePlugin.php` (lines 90-101) + `tests/Small/ComposerPlugin/PhiveUpdatePluginTest.php` to Phases 3/4**, and correct the problem statement: the plugin — firing on every consumer `composer update` — is a co-equal (arguably the primary) cause of the dirty-checkout pain. Missing-dir handling must become a silent skip for mid-upgrade compat.
- [ ] **Resolve the phive.xml contradiction (T3.1/T3.3)**: a self-built phar has no PHIVE source; keep it out of `phive.xml`, verify it separately in `tool-install.bash`, exclude it from the update-mode `rm` glob (`scripts/tool-install.bash:105-109`), and wire update mode to `build-rector-phar.bash` instead. Otherwise a routine maintainer `composer update` deletes `rector.phar` unrecoverably.
- [ ] **Pin the build input**: decide T2.2 now — keep a tracked build manifest (composer.json + lock relocated out of consumer-touched paths) or an explicit version argument recorded in `phive.xml`/commit; do not delete `tools/rector/composer.lock` without a successor pin (see `CLAUDE/prepush-verification.md:49-51` for why the pin exists).
- [ ] **Widen the spike (T1.3)**: multi-file corpus that forces parallel worker spawn; golden-master dry-run diff vs the current `tools/rector` install over php-qa-ci's own src/tests (catches the silent `stubs-rector` skip at `BootstrapFilesIncluder.php:37`); assert the dry-run **exit code 2** contract; run from the vendored layout; fixture consumer with a composer-required `phpstan/phpstan`.
- [ ] **Add an explicit task for `.github/workflows/update-deps.yml:71,121-125`** — its lock-diff update-detection flow dies with the sub-project and its phar-rebuild replacement needs Box + `phar.readonly=0` in CI.
- [ ] **Decide the `php8.3` branch strategy** (`CLAUDE.md:358`) and the phar's PHP floor before Phase 4 deletes the shared mechanism.

## Nice-to-have

- Correct the research's "collision structurally impossible" claim (§1a) — `--autoload-file` is an in-process surface; same as today, but the record should be accurate.
- Record a quantified Phase-5 tripwire (repo pack size / number of committed rebuilds / bump cadence) so "defer sister repo" cannot silently become "never"; state the intended Rector bump cadence.
- Configure Box reproducible builds (timestamps) so identical inputs produce identical phars; consider building the first shipped phar without the Php compactor to reduce spike variables.
- Make `check-requirements` an explicit box.json decision (it embeds the build manifest's `php: ^8.3` floor and ext checks).
- One-sentence closure on composer `conflict` (not needed) and a release note that the phar makes Rector installs fully offline (the old model needed network for first-use `composer install`).
- Update the doc set in one sweep: `docs/ci.md:39`, `docs/github-actions.md:179`, `docs/pipeline.md`, `docs/phpqa-tools.md`, `docs/coding-standards.md`, `CLAUDE.md:76`, `README.md`, `PhpStanGuardPlugin.php:20` comment, `.gitignore:22`.

### Critical Files for Implementation

- /workspace/src/ComposerPlugin/PhiveUpdatePlugin.php
- /workspace/scripts/tool-install.bash
- /workspace/includes/generic/rector.inc.bash
- /workspace/phive.xml
- /workspace/.github/workflows/update-deps.yml
