# BASH-CORE + ARCHITECTURE Remediation Plan (v1)

Author: bash-refactor planning agent (Fable). Date: 2026-07-15.
Status: Draft for maintainer review. PLANNING ONLY — no code changed by this document.

Binding inputs: `synthesis/findings-master-1.md` (work queue), `synthesis/bash-verdict-1.md`
(KEEP BASH; implement conditions C1–C6), `audits/bash-quality-1.md`,
`audits/architecture-1.md`. Backward compatibility is a hard constraint at every
step (tool `-t` names/aliases, `-p`, env vars, `qaConfig/*` override paths, config
cascade, exit-code semantics). Consumers must be able to update through any
intermediate release with no changes of their own.

This plan covers the BASH-CORE (`bin/qa` + `includes/**`) and ARCH findings, plus
the two PROCESS findings that gate them (M-028 test harness, and the shellcheck
gate). It maps the six mandatory conditions from the verdict onto concrete work:

| Verdict condition | Delivered by |
|---|---|
| C1 shellcheck gate | WP-B0 |
| C2 shared driver + declarative metadata | WP-B3 |
| C3 gate-liveness invariant | WP-B3 (design below) |
| C4 orchestration test harness | WP-B0 |
| C5 hygiene sweep with the refactor | WP-B5 (+ per-tool in WP-B3) |
| C6 keep pushing semantics to PHP | WP-B1 (annotations/psr4 already in `src/`), WP-B3 (drivers stay thin) |

---

## 0. Design principles that constrain every WP

1. **Test-first is non-negotiable.** WP-B0 (harness + shellcheck) lands before any
   behavioural change. Every later WP names the characterisation test that pins
   current behaviour *before* it is touched.
2. **The driver refactor is strictly incremental.** The legacy `runTool`
   source-and-self-execute path stays fully intact until the last tool migrates.
   Tools move one at a time; a half-migrated tree is a valid, shippable state.
3. **Additive, not replacing, the public surface.** New machinery (registry,
   driver, `deriveConfig` seam) is added; names, aliases, env vars and override
   paths are untouched. A `qaConfig/tools/<tool>.inc.bash` override still wins
   because it is still sourced first.
4. **Silent-pass is a bug class, not three bugs.** WP-B1 fixes the three live
   instances; WP-B3's C3 invariant makes the class structurally impossible so it
   cannot recur.

---

## WP-B0 — Test harness + shellcheck baseline (C1, C4; closes M-028)

**Goal:** stand up the two gates that make "keep bash" defensible and that would
have caught M-001/M-002/M-003, before any code that changes behaviour.

### B0.1 Harness choice (DECISION — see decision list D-4)

Two viable options, both already precedented or trivial here:

| Option | What it is | Pros | Cons |
|---|---|---|---|
| **A. PHPUnit-shells-out** (recommended) | Extend the existing `tests/Large/**` pattern (`InfectionDiffModeTest`): a PHP test `exec`s `bash <fragment>` / `bin/qa -t <tool>` in a stubbed temp environment and asserts on captured argv / exit code / output. | Zero new dependency; already proven in-repo (`InfectionDiffModeTest`, `Arkitect`, `Markdown` Large tests); runs inside the phpunit gate the project already ships; contributors already know PHPUnit; black-box integration shape is exactly what the silent-pass and driver tests need ("did the binary actually get invoked?"). | Coarser than function-level unit tests; each test pays subprocess start-up cost (mitigated by `#[Large]` grouping). |
| **B. bats-core** | A bash-native test runner; source a fragment, call a function, assert. | Finer-grained unit tests of individual bash functions; idiomatic for bash. | New tool to install/pin/PHIVE and to teach; a *second* test substrate alongside PHPUnit; CI must learn it; nothing in-repo uses it today. |

**Recommendation: Option A as the primary harness.** The load-bearing tests here
are integration-shaped (boot sequence, config cascade ordering, "tool X invokes
binary Y", driver retry/read-only/aggregate behaviour) — precisely what the
shells-out pattern does well, and it adds no dependency. Keep **bats as a
deferred, optional** addition only if, during WP-B3, we find we want
micro-unit coverage of individual driver helper functions in isolation; do not
adopt it pre-emptively.

### B0.2 Test inventory to author in B0

- **Boot-sequence smoke:** `bin/qa -h` and a no-op single-tool run reach the lock
  phase and exit cleanly; pins the two-`cd` config/run split (M-078) so B3 cannot
  silently break cwd assumptions.
- **Config-cascade ordering characterisation:** a test that sets
  `phpUnitCoverage`/`useInfection`/`mutationScoreIndicator` in a temp
  `qaConfig/qaConfig.inc.bash` and asserts the *current* (buggy, frozen) derived
  values. This deliberately pins today's behaviour so WP-B2's fix is a reviewed,
  intentional diff, not an accident.
- **Per-tool "binary is invoked" smoke (the gate-liveness spec):** one test per
  leaf tool asserting the underlying binary/phar is actually exec'd when the tool
  runs. For `psr4Validate`, `phpunitAnnotations`, `phpStrictTypes` these tests are
  written as the SPEC and are **expected to fail on the current tree** — they
  document M-001/M-002/M-003 and become the green gate for WP-B1. For all other
  tools they characterise current behaviour ahead of WP-B3.
- **Driver-behaviour tests (stubs, authored now, exercised as B3 lands):** retry
  loop honours `tryAgainOrAbort`/CI; read-only dry-run mapping; aggregate
  subshell containment; crash-threshold banner.

### B0.3 shellcheck gate (C1)

- Add `scripts/shellcheck.bash` that runs `shellcheck` over `bin/qa`, every
  `includes/**/*.inc.bash`, and `scripts/*.bash` (scripts dir owned by the
  separate bash-scripts plan, but the gate should cover it from day one).
- Wire it into the repo's OWN CI (`.github/workflows/`), not into `bin/qa` (it is
  not a consumer-facing QA tool). Also expose it as a composer script for local
  use.
- **Baseline strategy:** the tree has real SC findings today (SC2068 array
  re-split, SC2046, SC2086) and a large expected-noise tail (SC2154 cross-file
  92 hits, SC2155 59 hits). Establish the gate with an explicit suppression
  baseline: globally `disable=SC2154` (intrinsic to the source-graph
  architecture) and file-scoped `# shellcheck disable=` only where justified;
  treat SC2068/SC2046/SC2086-on-invocation as **must-fix**, tightened as WP-B5
  and the WP-B3 migrations remove them. The gate fails on any new error-class
  finding immediately; the benign-noise codes stay summarised, not itemised.

**M-IDs closed:** M-028 (and the C1/C4 conditions).
**Files touched:** `tests/Large/**` (new), `tests/Small/**` (new, if any pure
helper unit tests), `scripts/shellcheck.bash` (new), `.github/workflows/*.yml`,
`composer.json` scripts, a shellcheck baseline/config (`.shellcheckrc`).
**Effort:** M. **Risk:** very low — additive, no runtime behaviour change; the
only "risk" is red CI on the three known-failing spec tests, which is intended
and is what unblocks WP-B1.
**Verification:** the suite runs in CI; the three silent-pass spec tests are RED
(documenting the bugs); shellcheck runs green against its baseline.
**Rollback:** delete the workflow job / test files; nothing else references them.

---

## WP-B1 — CRITICAL silent-pass gates (closes M-001, M-002, M-003, M-004, M-024)

First code changes after the harness. Each turns a B0 spec test from RED to GREEN.

### B1.1 M-001 — restore PSR-4 validation
`includes/generic/psr4Validate.inc.bash` (currently 0 bytes) is restored to invoke
the real, tested `bin/psr4-validate`, honouring the ignore list that
`setConfig.inc.bash:32-33` already resolves into `psr4IgnoreList` (today a dead
`readarray`). Standard retry loop:

```bash
psr4ExitCode=99
while ((psr4ExitCode > 0)); do
  if phpNoXdebug -f "$binDir"/psr4-validate -- "${pathsToCheck[@]}"; then
    psr4ExitCode=0
  else
    psr4ExitCode=$?; tryAgainOrAbort "PSR-4 Validate"
  fi
done
```

- Add a `usePsr4Validate=${usePsr4Validate:-1}` opt-out (mirrors `useArkitect`,
  `useInfection`) so a consumer whose tree would newly fail can stage the fix
  without pinning an old release — this is what makes the behavioural change
  consumer-safe (D-6).
- Wire `psr4IgnoreList` / `psr4IgnoreListPath` into the invocation so the
  now-consumed ignore list stops being dead.

### B1.2 M-002 — PHPUnit annotations: BOTH options drafted (DECISION D-1)

The check is 100% commented (`phpunitAnnotations.inc.bash`) yet advertised as a
live `-t ann` gate; `bin/phpunit-check-annotation` and
`src/PHPUnit/CheckAnnotations.php` exist but are never invoked.

- **Option A — restore (modernised).** Reinstate the fragment to run
  `phpNoXdebug "$binDir"/phpunit-check-annotation "$testsDir"` inside the standard
  retry loop; verify `CheckAnnotations.php` still matches current PHPUnit-10
  attribute conventions and update it if it asserts docblock `@test`/`@covers`
  style that attributes have superseded. Add `useAnnotationsCheck=${...:-1}`
  opt-out for consumer staging.
- **Option B — formally retire.** Remove the fragment, the
  `allLintingTools.inc.bash:44` call, the `ann | phpunitAnnotations` alias and
  usage line in `options.inc.bash`, the `phpunitAnnotations` entry in
  `NON_PATH_SUPPORTING_TOOLS`, `bin/phpunit-check-annotation`,
  `src/PHPUnit/CheckAnnotations.php` (and its tests), and the doc references.
  Nothing is left claiming a gate that does nothing.

**Recommendation: Option B (retire), unless the maintainer knows a consumer relies
on annotation enforcement.** Rationale: the check has been inert long enough that
no consumer build currently depends on it firing, so retiring breaks nothing;
restoring it can *newly* fail consumer suites for a convention (`@test`/`@covers`
docblocks) that PHPUnit attributes + PHPStan/CS-Fixer now largely cover. Retire is
the lower-consumer-risk way to resolve the CRITICAL ("stop claiming an inert
gate"). Preserve `bin/phpunit-check-annotation` + `CheckAnnotations.php` in git
history so a future opt-in restore is cheap. **Decision owner: maintainer** — this
is a product/standards call, not a mechanical one.

### B1.3 M-003 + M-004 — strict-types gate
`includes/generic/phpStrictTypes.inc.bash`:
- **M-003 find precedence:** group the name tests so `-print` (implicit) applies to
  both extensions: `find "$d" \( -name '*.php' -o -name '*.phtml' \) -exec grep -L 'strict_types' {} \;`.
  Today the bare `*.php` branch is consumed by `-exec`'s implicit suppression of
  `-print`, so only `.phtml` is ever scanned.
- **M-004 CI + read-only + quoting:** add a non-interactive branch —
  `if [[ "false" != "${CI:-false}" || "true" == "${qaReadOnly:-false}" ]]` — that
  collects offenders and either FAILS with `reportReadOnlyWouldModify`-style
  guidance (read-only) or fails with the offender list (CI, no writes). Only the
  interactive local-writable path may apply a fix, and it must NOT use `sed -i`
  (forbidden and unsafe on unquoted paths): replace with a safe insert (PHP
  one-liner or `printf` + atomic move) operating on a properly quoted `"$f"`.
  Quote `"$d"`/`"$f"` throughout.

### B1.4 M-024 — packageType aggregate leak
`includes/generic/allLintingTools.inc.bash:23`: change `runTool packageType` to
`runToolGuarded packageType` so a failing package-type check is contained in the
subshell and recorded in `qaFailedTools` instead of `exit 1`-ing the whole run in
aggregate/read-only mode.

**Files touched:** `includes/generic/psr4Validate.inc.bash`,
`phpunitAnnotations.inc.bash`, `phpStrictTypes.inc.bash`,
`allLintingTools.inc.bash`; `includes/options.inc.bash` (only if Option B retire);
`bin/phpunit-check-annotation`, `src/PHPUnit/CheckAnnotations.php` (Option B);
`setConfig.inc.bash` (consume `psr4IgnoreList`).
**Effort:** M. **Risk:** MEDIUM — this is the one WP that intentionally changes
observable gate behaviour for consumers (psr4 and strict-types now actually run in
CI). Backward-compat argument: these gates are *documented as active*, so making
them fire matches the contract consumers already read; the `use<Tool>=0` opt-outs
plus a clearly-communicated version bump (D-6) let any consumer whose tree newly
fails update through the release and stage the fix. Exit-code semantics for a
*passing* tree are unchanged.
**Verification:** the three B0 spec tests flip RED→GREEN; a new aggregate-mode
test asserts a failing `packageType` no longer aborts sibling linters; a
read-only test asserts `phpStrictTypes` reports-not-mutates.
**Rollback:** revert per-file; the `use<Tool>=0` flags let a consumer disable
without a code revert.

---

## WP-B2 — Config-ordering fix (closes M-009, M-044; touches M-041, M-043)

### B2.1 Mechanism (DECISION D-5)

Root cause (AR-001/002): `setConfig` derives dependent values at `bin/qa:162`,
but the project's `qaConfig.inc.bash` is not sourced until `bin/qa:177`. Any value
`setConfig` derives into a *renamed* var (`infectionMutationScoreIndicator` from
`mutationScoreIndicator`) or any decision computed from a project-settable input
(`useInfection` from `phpUnitCoverage`) freezes at the generic default.

Three candidate mechanisms:

| Mechanism | Sketch | Verdict |
|---|---|---|
| Move override sourcing before `setConfig` | source `qaConfig.inc.bash` at ~`:160` | Rejected: `setConfig` also resolves `projectConfigPath`, `varDir`, `configPath` that the override legitimately reads; moving the override earlier breaks overrides that depend on those seeds. |
| **Split `setConfig` into seed + derive** (recommended) | `setConfig` seeds raw defaults + `configPath` resolution only (no dependent derivations); a new `deriveConfig` step runs AFTER the override and computes `useInfection`, the xdebug/coverage coupling, `infectionMutationScoreIndicator`, `infectionCoveredCodeMSI`, etc. | Chosen: one clear "derive after override" seam; smallest behavioural delta; keeps all var names/defaults identical. |
| Lazy derivation at point-of-use | each derived value recomputed where consumed (as `infection.inc.bash` already does for MSI) | Rejected as the general fix: scatters policy across runners, doesn't generalise, and multiplies the exact workaround the audit flagged as fragile. |

### B2.2 Ordering diagram

```
CURRENT (buggy):
  :155 setPaths
  :162 setConfig      → seeds defaults AND derives useInfection / MSI / coverage   ← too early
  :164 -p reset
  :177 source qaConfig.inc.bash (override)                                          ← inputs arrive AFTER derivation
        └─ project's phpUnitCoverage=0 / mutationScoreIndicator=74 ignored by frozen derivations

PROPOSED:
  :155 setPaths
  :162 setConfig      → seeds raw defaults + configPath resolution ONLY (no dependent derivations)
  :164 -p reset
  :177 source qaConfig.inc.bash (override)                                          ← inputs arrive
  :NEW deriveConfig   → derive useInfection, coverage↔xdebug coupling, infectionMSI/coveredMSI, etc.
        └─ now sees the project's values
```

### B2.3 Scope
- Move ONLY the dependent derivations from `setConfig.inc.bash` (lines ~68–72
  `useInfection`, ~57–60 `phpUnitCoverage`↔xdebug, ~81–83 MSI) into `deriveConfig`;
  leave raw `${x:-default}` seeds in `setConfig`.
- **M-044 / AR-003:** delete the redundant CI re-derivation in
  `setConfig.inc.bash:96-100`; CI is already settled in `bin/qa:81-95`. One
  detector, one value.
- **M-041:** `phpUnitCoverage` default is `:-1` in code vs `:-0` in docs — code is
  correct; no code change (docs handoff H-3). Keep the default; the ordering fix
  simply makes a project override of it take effect on `useInfection`.
- **M-043:** `skipUncommittedChangesCheck` is read only by the dead
  `checkForUncommittedChanges` (M-022). Its removal is owned by WP-B4; note the
  dependency so the seed isn't left orphaned.
- The `infection.inc.bash` run-time MSI re-derivation workaround can be simplified
  once `deriveConfig` is correct, but leave it in place until B3 migrates infection
  (belt-and-braces; no behaviour change).

**Files touched:** `includes/generic/setConfig.inc.bash`, `bin/qa` (new
`deriveConfig` call site).
**Effort:** S–M. **Risk:** low. Backward-compat argument: identical variable
names and defaults; only the *timing* of derivation moves later. The one
theoretical regression is a consumer who (accidentally) relied on the frozen
value — vanishingly unlikely and never intended. The B0 characterisation test
pins today's frozen behaviour; B2 deliberately flips it and updates that test with
a documented rationale, so the change is reviewed, not silent.
**Verification:** the B0 ordering characterisation test is updated to assert the
project override now wins; add cases for `phpUnitCoverage=0 ⇒ useInfection=0` and
`mutationScoreIndicator=74 ⇒ infection floor=74`.
**Rollback:** re-inline the derivations into `setConfig`; single-file revert.

---

## WP-B3 — Shared driver + declarative metadata (C2, C3; closes M-010, M-011; addresses AR-004/010/012, folds M-025/M-026)

The centrepiece. Delivered incrementally; the legacy path stays live throughout.

### B3.1 Per-tool metadata schema

A single registry (`includes/generic/toolRegistry.inc.bash`, overridable per
project via `qaConfig/toolRegistry.inc.bash`) declares, per canonical tool name, a
record with these fields:

| Field | Values | Purpose |
|---|---|---|
| `name` | canonical (e.g. `phpstan`) | key |
| `aliases` | space list (`stan`) | feeds the options alias map (kills M-011 sync) |
| `pathSupport` | `yes` \| `no` | feeds `tool_supports_paths` (kills M-010 array) |
| `readOnlyBehaviour` | `none` \| `dryRun` \| `reportMutate` | rector/fixer=`dryRun`; composerChecks/phpStrictTypes=`reportMutate`; else `none` |
| `retryPolicy` | `none` \| `retry` \| `crash:<N>` | `crash:1` phpstan/arkitect, `crash:2` phpunit |
| `logArchival` | `none` \| `<logDir>:<logFile>` | drives `archiveToolLog` uniformly |
| `jsonSupport` | `none` \| `native` | phpstan=`native` |
| `timing` | `on` \| `off` | drives toolStart/Complete/Failed (M-023 wire) |
| `command` | fragment provides a `buildToolCommand` hook | the only thing a migrated fragment must supply |

Bash impl: one associative array per field keyed by tool name (bash 4+, already
assumed), populated in the registry file. `options.inc.bash` DERIVES its alias
`case` map, `PATH_SUPPORTING_TOOLS`, and `NON_PATH_SUPPORTING_TOOLS` from the
registry — so the "VERIFIED by reading tool files" hand-mirrors (M-011, AR-010)
become generated, and the psr4/annotations drift they already contain is
structurally impossible.

### B3.2 Driver control flow (`runToolDriven <tool>` in `functions.inc.bash`)

```
runToolDriven <tool>:
  meta ← registry[tool]                       # fail loudly if no record
  cmd  ← source fragment → buildToolCommand    # fragment sets `toolCommand=(...)` or `toolRan=1`
  ── C3 GATE-LIVENESS ────────────────────────────────────────────────
  if buildToolCommand produced NO command and did NOT set toolRan:
      FAIL LOUDLY: "tool <tool> ran nothing — dead gate"     # M-001/2/3 class impossible
  ── execute ─────────────────────────────────────────────────────────
  loop:
    run cmd under if-condition capture (errexit-safe; never `set +e`)
    if logArchival: pipe to tee, read ${PIPESTATUS[0]}, archiveToolLog
    map readOnlyBehaviour: dryRun→dry-run flags + reportReadOnlyWouldModify on the tool's pending-change code
    exit > crashThreshold → crash banner, exit 1 (no retry)
    exit in 1..threshold  → tryAgainOrAbort <label> (retry)
    exit 0 → break
  if timing=on: toolStart/…/toolComplete|toolFailed  (wires M-023)
```

Aggregate mode is unchanged: `runToolGuarded` wraps `runToolDriven` in the same
subshell it already uses for `runTool`, so `exit` containment is preserved.

### B3.3 C3 gate-liveness invariant (the anti-silent-pass guarantee)

The migrated contract inverts the current one: a fragment no longer *self-executes*
by being sourced — it **builds a command** (`toolCommand=(...)`) or, for bespoke
tools that must run inline logic (branchNamePolicy, infection, composerChecks),
sets `toolRan=1` explicitly. The driver asserts that one of the two happened. An
empty file, a fully-commented file, or a fragment whose only code paths are dead
therefore **fails the run loudly** instead of passing by doing nothing. This is
what makes the FS-008/FS-010/BQ-007 bug class impossible for every migrated tool;
the B0 per-tool "binary invoked" tests are the CI expression of the same invariant.

### B3.4 Migration order (easiest → hardest)

The legacy `runTool` handles any not-yet-migrated tool; the `all*Tools` groups
call a dispatch that routes migrated names to `runToolDriven` and the rest to the
legacy path. Order:

1. `phploc` — trivial, no retry, no logs (also lands the M-081 `failurePolicy=never` guarantee, D-3).
2. `packageType`, `sensitiveParameterUsage` — clean if-condition (Pattern A).
3. `psr4Validate` — freshly restored in B1; simple retry.
4. `phpLint`, `markdownLinks`, `composerRequireChecker` — Pattern C (`set +e`); driver removes the toggle and the `eval`/IFS laundering (M-026).
5. `phpStrictTypes` — post-B1; now CI-safe and `reportMutate`.
6. `twigLint`, `yamlLint` (symfony) — Pattern C; fixes M-051 typo + dir guard en route.
7. `phpstan`, `phpArkitect` — Pattern B (tee + PIPESTATUS + archive + crash code); driver absorbs the crash-banner + `eval` crash path (M-026), quoting (M-025).
8. `phpunit` — Pattern B ×2 logs; fold in the coverage memory-limit fix (AR-011/M-047).
9. `rector`, `phpCsFixer` — dual read-only; thin `dryRun` specialisation over the driver.
10. `composerChecks` — add the missing read-only gate (AR-008/M-027) as it migrates to `reportMutate`.
11. `branchNamePolicy` — bespoke `_bnp_run` fn-wrapped; wrap thinly, sets `toolRan=1`.
12. `infection` — most complex; migrate LAST. Its existing `InfectionDiffModeTest`
    pins the flag contract, so the migration is the safest-tested of all — but it
    is done last so the driver is fully proven first.

As each tool migrates, its M-025 unquoted-array and M-026 `eval` issues are fixed
at the point of use and its working vars are declared `local` (AR-012/M-074),
retiring reliance on the global `IFS=$'\n\t'` accident for that tool.

**Files touched:** `includes/functions.inc.bash` (driver + guarded wrapper),
`includes/options.inc.bash` (derive maps from registry), new
`includes/generic/toolRegistry.inc.bash`, every `includes/generic/*.inc.bash` and
`includes/symfony/*.inc.bash` (one at a time), the four `all*Tools.inc.bash`
(dispatch).
**Effort:** L (but sliced into ~12 small, independently shippable migrations).
**Risk:** MEDIUM, mitigated by increment + the B0 characterisation tests that pin
each tool before it moves. Backward-compat argument: names/aliases/override paths
unchanged (a `qaConfig/tools/<tool>.inc.bash` override is still sourced first and
still wins — an un-migrated override simply uses the legacy self-execute path); the
registry only *generates* the option maps that were hand-written, producing
identical user-facing errors; exit-code semantics per tool are pinned by
characterisation tests before and after each migration.
**Verification:** per-tool: the B0 "binary invoked" test + a characterisation test
asserting identical argv/exit for a representative input before vs after migration;
driver-level: retry/read-only/aggregate/crash tests; C3: a deliberately-emptied
fixture fragment must FAIL the run (regression test for the whole silent-pass
class).
**Rollback:** per tool — revert its fragment + remove its registry `command` hook;
the legacy path re-adopts it with no other change. The driver itself is dead code
until at least one tool routes to it.

---

## WP-B4 — Dead-code removal (closes M-022, M-045, M-046; M-023 decided here)

Split by dependency:

### B4a — pure deletes (after B0, independent of B3)
- **M-022:** delete `checkForUncommittedChanges()` (`functions.inc.bash:108-174`,
  carries a live interactive `git add -A; git commit` and its own bug) and
  `phpunitReRunFailedOrFull()` (`:176-209`). Both have zero callers.
  Consequently remove the now-orphan `skipUncommittedChangesCheck` seed (M-043)
  and hand its doc removal to the docs plan (H-4).
  **Wire-vs-delete (D — folded into recommendation):** DELETE, do not resurrect.
  Read-only mode plus the repo's `prevent-destructive-git` guard already cover the
  "don't clobber uncommitted work" concern; reviving an interactive auto-commit
  path is a net risk.
- **M-045:** delete the ~60 commented-out auto-fix lines in
  `composerRequireChecker.inc.bash`.
- **M-046:** delete the dead `TRAVIS`/`phpenv config-rm` branch
  (`allTestingTools.inc.bash:16-19`).
- Delete the `#pathsToCheck+=($binDir)` commented residue in `setPaths.inc.bash`.

### B4b — M-023 per-tool lock/timing (DECISION D-2; depends on B3)
`toolStart`/`toolComplete`/`toolFailed` (`lock.inc.bash:408-508`) are never called;
`recordCommandTiming` keys on the lock's empty `tool` field so full-run timings are
never recorded. Two open sub-questions from the master register must be settled by
**one instrumented run** before acting: confirm the "full-run never recorded"
consequence.

**Recommendation: WIRE, via the WP-B3 driver.** The driver's `timing=on` field
gives these functions their natural, single call site, and keying timing on the
actual invoked tool makes the half-built ETA feature work. Doing it any other way
(scattered calls in each fragment) is the duplication the whole plan removes.
**Fallback:** if the maintainer prefers not to invest in ETA, DELETE the three
functions and the unused `tools[]`/`current_tool` JSON fields instead — the lock
file is runtime-only and gitignored, so there is no consumer contract to preserve.
**Decision owner: maintainer**, after the instrumented run.

**Files touched:** `includes/functions.inc.bash`, `composerRequireChecker.inc.bash`,
`allTestingTools.inc.bash`, `setPaths.inc.bash` (B4a); `lock.inc.bash`,
`timing.inc.bash`, driver (B4b).
**Effort:** B4a S, B4b M. **Risk:** low. Backward-compat: dead code has no
consumers by definition; the lock JSON is gitignored runtime state.
**Verification:** grep proves zero callers pre-delete; the full suite stays green;
B4b adds a timing-recorded assertion for a full run.
**Rollback:** git revert; nothing depends on the removed symbols.

---

## WP-B5 — Hygiene sweep (closes M-047–M-053, M-075; M-025/M-026 residual)

Most M-025/M-026 land inside B3 per-tool migrations; B5 is the residual sweep for
files B3 does not restructure (`lock`, `timing`, `branchNamePolicy` internals,
`options.inc.bash`, `bin/qa`) plus the shellcheck-baseline tightening.

- **M-047 / AR-011:** phpunit coverage uses `$phpBinPath` directly, bypassing
  `phpNoXdebug` and thus `phpqaMemoryLimit`. Apply the memory limit explicitly on
  the coverage run (`-d memory_limit=${phpqaMemoryLimit}`). (Lands with the B3
  phpunit migration; tracked here.)
- **M-048:** quote `"$(which composer)"` ×4 in `composerChecks.inc.bash` (SC2046).
- **M-049:** `branchNamePolicy.inc.bash:261` `rm -f /tmp/branchNamePolicy.*.err`
  globs shared `/tmp` — switch to a per-run `mktemp -d`; surface the `_bnp_errLog`
  contents on failure as its comment already promises.
- **M-050:** note the `lock.inc.bash:110` process-sub `tee` race (accept, document).
- **M-051:** fix `yamlLintExistCode` typo and add a `config/` existence guard
  (lands with B3 symfony migration).
- **M-052:** SC2155 sweep in `lock.inc.bash`/`timing.inc.bash` where a failed
  `jq`/`date` is silently swallowed (split declare+assign so `$?` survives).
- **M-053:** `bin/qa:31` composer-proxy self-parse regex keyed to literal
  `php-qa-ci` breaks on fork/rename — make the parse tolerant of the package
  directory name (derive from `COMPOSER_RUNTIME_BIN_DIR` mapping rather than a
  hard-coded string).
- **M-075:** `phpNoXdebug` clobbers a caller's `set -x`; save/restore it. Clean up
  `archiveToolLog`'s `.warned_high_count_$$` markers (trap on exit).
- **Also fold the jq-tempfile+atomic-mv 5× duplication (M-076)** into a
  `jqUpdateInPlace` helper while touching `lock.inc.bash`.
- INFO-tier consistency (M-079 echo/printf, M-078 `$DIR`/`$qaDir` dual naming,
  M-074 residual locals) as opportunistic cleanup, not gating.

**Files touched:** `composerChecks.inc.bash`, `branchNamePolicy.inc.bash`,
`lock.inc.bash`, `timing.inc.bash`, `bin/qa`, `phpunit.inc.bash`,
`includes/symfony/yamlLint.inc.bash`, `functions.inc.bash`.
**Effort:** M. **Risk:** low–medium (M-053 self-parse touches bootstrap — pin with
a boot-sequence test from B0). Backward-compat: pure correctness/robustness; no
surface change.
**Verification:** shellcheck baseline tightens (the must-fix codes drop to zero);
boot-sequence test covers M-053; targeted tests for M-047 (memory flag present),
M-049 (temp dir under mktemp).
**Rollback:** per-item git revert.

---

## Dependency graph (text DAG)

```
WP-B0 (harness + shellcheck)
  ├─► WP-B1 (CRITICAL gates)        [needs B0 spec tests]
  ├─► WP-B2 (config ordering)       [needs B0 characterisation]
  ├─► WP-B4a (pure deletes)         [needs B0 regression safety]
  └─► WP-B3 (driver + metadata)     [needs B0; best after B1 + B2]
        ├─ B1 SHOULD precede  (restored gates migrate cleanly)
        ├─ B2 SHOULD precede  (driver must not inherit the ordering bug)
        ├─► WP-B4b (M-023 wire)     [needs B3 driver call site]
        └─► WP-B5 (hygiene residual)[rides per-tool; residual after B3]
```

Hard edges: B0 → {B1, B2, B3, B4a}. B3 → B4b. Soft (recommended) edges: B1 → B3,
B2 → B3. B5 items are mostly attached to their B3 tool migration; the
non-tool files (lock/timing/bin/qa) sweep can run any time after B0.

## Suggested release slicing (each a consumer-safe update)

- **Release 1 — Internal gates.** WP-B0. No consumer-visible behaviour change
  (tests + CI only). Safe through-update.
- **Release 2 — Honest gates.** WP-B1 + WP-B4a. Behaviour-changing (psr4 and
  strict-types now run in CI); ship WITH the `usePsr4Validate` / strict-types
  opt-outs and a clearly-flagged version bump (D-6) so any consumer whose tree
  newly fails can update through the release and stage the fix. B4a deletes ride
  free (no surface).
- **Release 3 — Ordering fix.** WP-B2. Pure fix; only helps currently-ignored
  projects. Safe.
- **Releases 4…N — Driver rollout.** WP-B3 in small batches (1–3 tools per
  release), each internal and additive; WP-B4b and the matching WP-B5 items ride
  with the relevant tool's migration. Any intermediate release is a valid tree
  (legacy + migrated tools coexist).

---

## Decision list for the user

| # | Decision | Options | Recommendation | Owner |
|---|---|---|---|---|
| D-1 | M-002 PHPUnit annotations | A restore-modernised · B formally retire (remove fragment+call+bin+src+options+docs) | **B (retire)** — inert long enough that nothing depends on it; restoring can newly fail consumer suites for a convention attributes/PHPStan now cover. Preserve bin+src in history for opt-in. | Maintainer (product/standards call) |
| D-2 | M-023 per-tool lock/timing | Wire via driver · Delete functions+JSON fields | **Wire** (driver gives the call site; makes ETA real), after one instrumented run confirms full-run timings are unrecorded; else delete. | Maintainer, post-instrumented-run |
| D-3 | M-081 phploc "cannot fail" | Make it code-guaranteed · Docs-only correction | **Code-guaranteed** — give phploc `failurePolicy=never` in the driver so the documented claim becomes true. | Maintainer |
| D-4 | Test harness | PHPUnit-shells-out · bats-core | **PHPUnit-shells-out** — zero new dep, already proven in-repo, integration shape fits; defer bats unless micro-unit coverage is later wanted. | Maintainer |
| D-5 | M-009 ordering mechanism | Move override earlier · **Split setConfig seed/derive** · Lazy at point-of-use | **Split seed/derive** — smallest behavioural delta, one clear "derive after override" seam, identical names/defaults. | Maintainer |
| D-6 | Release-2 behaviour change | Silent · Flags + communicated version bump | **Flags (`usePsr4Validate`, strict-types opt-out) + explicit changelog/version bump** so consumers can update-through and stage. | Maintainer (release policy) |

---

## Out-of-scope (and docs handoff points)

**Out of scope for this plan** (separate plans own them):
- BASH-SCRIPTS (`scripts/deploy-skills.bash`, `setup-*.bash`, `tool-install.bash`,
  `ci.bash`, `git-hooks/*`, `installUpdateInfection.bash`; M-013–M-021, M-063–M-073,
  M-060 orphan hook). Consumer-write safety is that plan's theme (g).
- DOCS corrections (M-005–M-008, M-029–M-043 docs rows, M-054–M-062, M-080, M-084).
- PHP-layer tests (M-077 composer plugins + PHPStan rules; M-060 hook doc).

**Handoff points — where THIS plan's code decisions unblock the docs plan:**
- **H-1 (D-1 M-002):** restore-vs-retire determines CLAUDE.md phase list, README
  tool list, `docs/phpqa-tools.md`, `docs/tools/`, and the options usage text
  (also resolves M-031 phase numbering, M-032, M-059).
- **H-2 (M-001 restored):** PSR-4 is genuinely live → docs phase list / phpqa-tools
  become accurate (M-031).
- **H-3 (WP-B2 + M-041):** `phpUnitCoverage` default `:-1` and the fixed ordering →
  correct the CLAUDE.md Key-Config block (M-041) and any coverage-default prose.
- **H-4 (WP-B4a + M-043):** `skipUncommittedChangesCheck` removed → drop it from
  CLAUDE.md Key Config.
- **H-5 (D-2 M-023):** wire-vs-kill sets what `locking-system.md` should say
  (M-038, M-033) about per-tool tracking and committed timing data.
- **H-6 (D-3 M-081):** the phploc guarantee decision makes the "cannot fail" claim
  either true-and-documentable or to-be-softened.
- **H-7 (WP-B3 registry):** the tool registry becomes the single source of truth
  for path support and aliases → docs for read-only/aggregate/`--json` modes
  (M-034) and path support can point at it instead of re-listing.
- **H-8 (new flags):** `usePsr4Validate`, any `useAnnotationsCheck` →
  `docs/configuration.md` gains their entries.
