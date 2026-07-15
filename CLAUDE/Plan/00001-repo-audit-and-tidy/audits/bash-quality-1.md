# Bash Quality Audit — php-qa-ci core pipeline

**Auditor:** senior bash reviewer (audit-only)
**Date:** 2026-07-15
**Scope:** `bin/qa`, `includes/functions.inc.bash`, `includes/options.inc.bash`,
`includes/generic/*.inc.bash` (26 files), `includes/symfony/*.inc.bash` (4 files).
Total 3,883 lines of bash across 32 files. All files read in full.

## Method

1. **shellcheck 0.9.0** on every file. `bin/qa` linted as a script; every
   `.inc.bash` fragment linted with `-s bash -x` (follow-sources). The sourced
   fragments produce heavy **SC2154** (var set in a different file) noise —
   92 hits — which is expected for this source-graph architecture and is
   filtered out below; I report signal, not that noise. **SC2155** (declare +
   assign masking `$?`, 59 hits) and **SC2034** (apparently-unused, 33 hits,
   mostly cross-file) are likewise largely benign here and summarised rather
   than itemised. The high-value codes I *do* itemise are **SC2068** (unquoted
   array re-split, `error` severity), **SC2206/SC2207** (word-split into array),
   **SC2145** and **SC2046/SC2086** where they touch tool invocation.
2. **Manual review** for what shellcheck cannot see: errexit/retry-loop
   interactions, the load-bearing `IFS=$'\n\t'` global, sourced-scope `exit`
   vs `return`, dead code, and the "silently does nothing" class of bug.
3. **Duplication catalogue** of the retry-loop pattern (8+ variants).
4. **Test-coverage** survey of the bash layer.

A key global fact underpins several findings: **`bin/qa` and
`options.inc.bash` both set `IFS=$'\n\t'`.** This removes the space character
from the word-splitting set, so most unquoted `${array[@]}` expansions do *not*
split on spaces at runtime. That is an accidental safety net, not a designed
one — see BQ-004.

---

## 1. Executive scorecard

| File | Lines | Real shellcheck issues | Manual findings | Grade | Note |
|---|---:|---:|---:|:--:|---|
| `bin/qa` | 352 | SC2086×9, SC2145×2, SC2206×2, SC2046×1 | 3 | C | Entry point; unquoted `$qaDir`/`$@` throughout, relies on IFS hack |
| `includes/functions.inc.bash` | 582 | SC2207×2, SC2086 several | 4 | C+ | 2 large dead functions; good `runToolGuarded`/`archiveToolLog` |
| `includes/options.inc.bash` | 220 | SC2086 (printf `$arg`) ×~6 | 2 | B | Solid arg parsing; stale PATH-support tables |
| `includes/generic/allCodingStandardsTools.inc.bash` | 15 | 0 | 0 | A | Clean |
| `includes/generic/allLintingTools.inc.bash` | 59 | 0 | 2 | C | `runTool packageType` breaks aggregate; calls no-op tools |
| `includes/generic/allStaticAnalysisTools.inc.bash` | 35 | 0 | 0 | A | Clean |
| `includes/generic/allTestingTools.inc.bash` | 30 | 0 | 1 | B | Dead TRAVIS/phpenv branch |
| `includes/generic/branchNamePolicy.inc.bash` | 267 | ~0 | 1 | A- | **Model file**: function-wrapped, `return`-based, documented |
| `includes/generic/composerChecks.inc.bash` | 44 | SC2046×4 (`$(which composer)`) | 1 | C | Unquoted command-sub; `set +e` never balanced back inside diagnose block |
| `includes/generic/composerRequireChecker.inc.bash` | 117 | SC2086×2 | 1 | B- | ~60 lines commented-out dead block |
| `includes/generic/infection.inc.bash` | 269 | minimal | 1 | A- | Complex but justified & documented; only bash file with a test |
| `includes/generic/lock.inc.bash` | 565 | SC2155 heavy | 2 | C+ | ~100 lines dead (toolStart/Complete/Failed never called) |
| `includes/generic/markdownLinks.inc.bash` | 22 | SC2086 | 0 | B | Pattern-C retry |
| `includes/generic/packageType.inc.bash` | 28 | 0 | 0 | A | Clean if-condition retry |
| `includes/generic/phpArkitect.inc.bash` | 122 | SC2068×2 | 0 | B+ | Good Pattern-B retry, well documented |
| `includes/generic/phpCsFixer.inc.bash` | 84 | SC2068×2 | 0 | B | Dual read-only/writable, clean |
| `includes/generic/phpLint.inc.bash` | 24 | SC2207, SC2068 | 1 | C | Needless `eval`; unquoted array to tool |
| `includes/generic/phpStrictTypes.inc.bash` | 22 | SC2086 (`$f`, `$d`) ×several | 2 | **D** | **No-op in CI**; `sed -i` on unquoted user paths |
| `includes/generic/phploc.inc.bash` | 4 | SC2068 | 0 | B | Trivial |
| `includes/generic/phpstan.inc.bash` | 111 | SC2068×3 | 1 | B- | `eval` on crash path; otherwise strong Pattern-B |
| `includes/generic/phpunit.inc.bash` | 204 | SC2068×3 | 1 | B- | Long but justified; unquoted `${pathArgs[@]}` |
| `includes/generic/phpunitAnnotations.inc.bash` | 17 | 0 | 1 | **D** | **Entirely commented out** — a no-op advertised as a check |
| `includes/generic/prepareDirectories.inc.bash` | 53 | 0 | 0 | A | Idempotent managed-block, clean |
| `includes/generic/psr4Validate.inc.bash` | 0 | — | 1 | **F** | **0 bytes — PSR-4 validation never runs** |
| `includes/generic/rector.inc.bash` | 106 | minimal | 0 | A- | `runRectorConfig` fn, dual-mode, documented |
| `includes/generic/sensitiveParameterUsage.inc.bash` | 44 | 0 | 0 | A | Clean if-condition retry |
| `includes/generic/setConfig.inc.bash` | 100 | SC2155 | 1 | B- | reads `psr4IgnoreList` nothing consumes |
| `includes/generic/setPaths.inc.bash` | 21 | SC2206×2 | 0 | B | Unquoted `+=($testsDir)` |
| `includes/generic/timing.inc.bash` | 307 | SC2155 | 0 | B+ | Cohesive jq/ETA module, testable |
| `includes/symfony/allLintingTools.inc.bash` | 21 | 0 | 0 | B | Fine |
| `includes/symfony/setConfig.inc.bash` | 9 | 0 | 0 | A | Fine |
| `includes/symfony/twigLint.inc.bash` | 17 | SC2068 | 1 | C+ | Unquoted `${twigDirectories[@]}`, no dir-exists guard |
| `includes/symfony/yamlLint.inc.bash` | 12 | SC2068 | 1 | C | Same + `yamlLintExistCode` typo |

Grade key: A excellent, B good, C acceptable-with-issues, D serious problem,
F broken.

---

## 2. Findings by severity

### CRITICAL — real bug, can misbehave today

---

**BQ-001 — PSR-4 validation silently never runs (empty tool file)**
`includes/generic/psr4Validate.inc.bash` (0 bytes) · `allLintingTools.inc.bash:7`

```bash
# allLintingTools.inc.bash
runToolGuarded psr4Validate     # sources an EMPTY file → no-op
```

`runTool`/`runToolGuarded` locate the tool by sourcing `…/psr4Validate.inc.bash`.
The file exists but is empty, so sourcing it is a successful no-op that *always
passes*. Yet:
- `bin/psr4-validate` exists and is a real, tested binary (`tests/assets/psr4`).
- `options.inc.bash` advertises `-t psr|psr4` and lists it as path-supporting.
- `setConfig.inc.bash:32-33` resolves the ignore list and `readarray`s it into
  `psr4IgnoreList` — which **nothing consumes**.

Net effect: a documented Phase-2 gate (PSR-4 namespace/dir compliance) has been
dead across the whole estate, and `vendor/bin/qa -t psr4` reports success while
doing nothing. This is the single highest-impact finding.

*Fix sketch:* restore the fragment to invoke the binary, e.g.
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
(honour `psr4IgnoreListPath`). If the check is intentionally retired, delete the
call, the option mappings, and the `setConfig` readarray instead of leaving a
silent stub.

---

**BQ-002 — Strict-types "enforcement" is a no-op in CI, and mutates unquoted paths**
`includes/generic/phpStrictTypes.inc.bash:1-22`

```bash
for d in "${pathsToCheck[@]}"; do
    for f in $(find $d -name '*.php' -o -name '*.phtml' -exec grep -L 'strict_types' {} \;); do
        echo "Found file with no strict types:"; echo $f
        read -p "Would you like to fix? " -n 1 -r          # <-- interactive, no CI branch
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            sed -i 's/<?php/<\?php declare(strict_types=1);/g' $f      # <-- unquoted $f
        else
            echo "skipped $f"
        fi
    done
done
```

Two defects in one file:
1. **No CI guard.** Every other interactive point in the codebase funnels
   through `tryAgainOrAbort`/`checkForUncommittedChanges`, which short-circuit
   when `CI != false`. This file calls `read` directly. Under CI/Claude/GitHub
   Actions (`bin/qa` forces `CI=true` for non-TTY) `read` gets EOF, `$REPLY` is
   empty, and every offending file is **"skipped"**. The tool can therefore
   *never fail and never fix* in automation — strict types are not enforced
   where enforcement matters. It should either FAIL like the read-only fixers
   (BQ pattern) or auto-fix non-interactively.
2. **Unquoted `$f` / `find $d`** in both the `sed -i` write and the loop. A path
   containing a space would break the write (the IFS hack only shields the
   `for` word-split, not `find $d` nor the `sed` argument). Also the
   `-name '*.php' -o -name '*.phtml'` has no parenthesised grouping around the
   `-exec`, so precedence is subtle.

*Fix sketch:* add `if [[ "false" != "${CI:-false}" ]]; then` non-interactive
enforcing branch that collects offenders and `exit 1`s with guidance; quote
`"$f"`, `"$d"`; group the `find` expression `\( -name '*.php' -o -name '*.phtml' \)`.

---

### MAJOR — latent bug or serious maintainability risk

---

**BQ-003 — `runTool packageType` bypasses aggregate mode**
`includes/generic/allLintingTools.inc.bash:23`

```bash
runTool packageType          # every sibling uses runToolGuarded
```

`runToolGuarded` runs the tool in a **subshell** so a leaf tool's `exit`
(via `tryAgainOrAbort` in CI) is contained and recorded in `qaFailedTools`.
`runTool` does not. So in aggregate (read-only CI) mode a failing `packageType`
check `exit 1`s the *entire* `bin/qa` process at the linting phase, defeating
the documented "run every tool, collect all failures" contract and preventing
later tools (strict-types, lint, require-checker, markdown) from running.

*Fix:* `runToolGuarded packageType`.

---

**BQ-004 — Unquoted `${array[@]}` passed to tools (SC2068), shielded only by the IFS hack**
`phpstan.inc.bash:59,61,75` · `phpunit.inc.bash` (`${pathArgs[@]}`, `${extraConfigs[@]}`, `${paratestConfig[@]}`) · `phploc.inc.bash:2` · `phpCsFixer.inc.bash` (`${pathsToCheck[@]}`) · `twigLint.inc.bash:8` · `yamlLint.inc.bash:5` · `setPaths.inc.bash:15-16`

Representative (phpstan):
```bash
analyse ${pathsToCheck[@]} \
```

shellcheck rates SC2068 an **error**. The lead's question — "which break on
paths with spaces?" — has a precise answer: **none break on spaces today,
because `IFS=$'\n\t'` removes space from the split set.** But this is fragile:

- **Glob expansion is still active** (no `set -f`). A checked path that contains
  a shell glob char, or a stray matching file in `$PWD`, will expand wrongly.
- **The shield is load-bearing and locally revoked.** `infection.inc.bash`
  does `backupIFS=$IFS; IFS=$standardIFS; …; IFS=$backupIFS`. Any unquoted array
  expansion evaluated while IFS is the default *would* split on spaces. Today
  infection doesn't expand `pathsToCheck` in that window, so it's safe — but the
  invariant "never expand an array unquoted" is the only thing keeping the whole
  design correct, and it is already violated everywhere.
- **Empty-array + `set -u`.** With `pathsToIgnore` seeded by a placeholder this
  is dodged, but the pattern is a foot-gun for any future empty array.

*Fix:* quote every array expansion at the point of use: `"${pathsToCheck[@]}"`,
`"${pathArgs[@]}"`, `"${twigDirectories[@]}"`, etc., and quote
`pathsToCheck+=("$testsDir")`. This makes the IFS hack unnecessary and the code
correct regardless of IFS state.

---

**BQ-005 — Dead code in `functions.inc.bash`: two unreachable functions (~90 lines)**
`checkForUncommittedChanges` (108-174) · `phpunitReRunFailedOrFull` (176-209)

Neither is called anywhere in `includes/` or `bin/`. `checkForUncommittedChanges`
even carries a live interactive `git add -A; git commit` path. Dead code that
performs writes is worse than inert dead code — it invites accidental
resurrection. Either wire them in (there are obvious call sites — an
uncommitted-changes gate before the mutating Phase-1 tools) or delete them.

---

**BQ-006 — Dead code in `lock.inc.bash`: per-tool tracking never invoked (~100 lines)**
`toolStart` (408-430) · `toolComplete` (440-469) · `toolFailed` (479-508)

These three functions maintain the lock JSON's `.current_tool` and `.tools[]`
audit trail, but nothing calls them (`grep` across `includes/`+`bin/` finds only
the definitions). Consequences that ship today:
- `.current_tool` is written `""` at acquire and never updated, so
  `displayLockStatus` (192) can never print "Currently running: …".
- The entire `.tools[]` timeline the JSON schema implies is always empty.

~18% of the file is dead, and the lock's headline feature (see *which* tool a
concurrent run is stuck on) does not work. Either call `toolStart/Complete/Failed`
from `runTool`/`runToolGuarded`, or remove them and simplify the JSON.

---

**BQ-007 — `phpunitAnnotations` is a fully-commented-out no-op still wired in**
`includes/generic/phpunitAnnotations.inc.bash` (every line commented) ·
called at `allLintingTools.inc.bash:44`, advertised as `-t ann`.

Same shape as BQ-001 (silent pass), lower impact because it is at least
*obviously* empty when opened. Options.inc even annotates the tool
`# ❌ Need to verify implementation`, i.e. the uncertainty was known and left in.
Decide: implement against `bin/phpunit-check-annotation` (it exists) or remove
the call + option + banner in `allLintingTools`.

---

**BQ-008 — `eval` on tool invocation**
`phpstan.inc.bash:75` · `phpLint.inc.bash:1`

```bash
# phpstan crash path
eval phpNoXdebug -f "$pharDir"/phpstan.phar -- analyse $pathsStringArray -c "$phpstanConfigPath" --debug -v
# phpLint
pathsStringArray=($(IFS=" " eval 'echo "${pathsToCheck[*]}"'))
```

`eval` over `$pathsStringArray` (derived from project paths) is a command-injection
surface if a scanned path ever contains shell metacharacters, and it is
unnecessary — the array can be expanded directly (`"${pathsToCheck[@]}"`). The
phpstan case is only reached on a crash, so impact is bounded, but it is the
riskiest single construct in the tree. `phpLint`'s `eval 'echo …'` reconstructs a
space-joined string only to re-split it — a no-op laundering of an array that
should just be `"${pathsToCheck[@]}"`.

*Fix:* drop both `eval`s; pass the array quoted.

---

### MINOR

- **BQ-009** `composerChecks.inc.bash:5,20,32,40` — `$(which composer)` unquoted
  (SC2046 ×4). Also the `set +e` at line 1 guards only the `diagnose` block and
  is re-enabled at line 6; readable but the "won't fail" contract rests on that
  balance. Quote `"$(which composer)"`.
- **BQ-010** `allTestingTools.inc.bash:16-19` — dead `TRAVIS`/`phpenv config-rm`
  branch. Travis-CI is retired; this can never fire in the supported
  environments. Remove.
- **BQ-011** `branchNamePolicy.inc.bash:261` — `rm -f /tmp/branchNamePolicy.*.err`
  globs a **shared** `/tmp` and would delete other users' matching files on a
  multi-user host. Prefer a per-run `mktemp -d` and remove the directory. Also
  the `_bnp_errLog` tempfile is created and appended to but its contents are
  never surfaced, despite the comment promising they are "kept … so genuine
  failures can be inspected".
- **BQ-012** `composerRequireChecker.inc.bash:~40-95` — ~60 lines of
  commented-out auto-fix logic. Delete or extract; commented code rots.
- **BQ-013** `lock.inc.bash:110` — `exec > >(tee -a "$QA_MASTER_LOG") 2>&1`.
  Process-substitution logging has a well-known race where output emitted after
  the script exits can be lost/interleaved because the `tee` is reaped
  asynchronously. Acceptable, but note it.
- **BQ-014** `yamlLint.inc.bash` — variable typo `yamlLintExistCode`
  (consistent within the file so it works) and no guard that
  `$projectRoot/config` exists before `lint:yaml`.
- **BQ-015** SC2155 (59 hits) — `local x=$(cmd)` masks the command's exit status.
  Mostly benign in the jq/timing helpers (return value unused), but worth a sweep
  in `lock.inc.bash`/`timing.inc.bash` where a failed `jq`/`date` is silently
  swallowed.

### INFO

- **BQ-016** Unquoted `$qaDir`, `$bashSource`, `$binDir`, `$@` throughout
  `bin/qa` (SC2086 ×9). Survives today via the IFS hack + no-spaces install
  paths, but an install path with a space would break `cd $qaDir` and the
  `realpath`/`grep` chain. Quote them.
- **BQ-017** `bin/qa:57,350` — `echo "… $0 $@ …"` (SC2145: string mixed with
  array). Cosmetic; use `$*`.
- **BQ-018** Header-comment richness is bimodal: `branchNamePolicy`, `infection`,
  `lock`, `timing`, `rector`, `phpArkitect` are *exemplary* (rationale, exit
  codes, escape hatches); `setPaths`, `allTestingTools`, `phpStrictTypes`,
  `phploc`, `yamlLint` are bare. Bring the bare files up to the house style.
- **BQ-019** `echo` vs `printf` is mixed even within single files
  (e.g. `options.inc.bash` uses `printf` for errors, `echo` for usage). Not a
  bug; a consistency target.

---

## 3. Duplication catalogue — the retry loop

The `exitCode=99; while … tryAgainOrAbort` idiom is reimplemented **11 times**
in five distinct variants with real divergences:

| Variant | Files | Exit capture | Log archive | Crash special-case | errexit handling |
|---|---|---|---|---|---|
| **A** if-condition | `packageType`, `sensitiveParameterUsage` | `if tool; then 0; else $?` | no | no | clean (no `set +e`) |
| **B** PIPESTATUS+archive | `phpstan`, `phpunit`, `phpArkitect` | `${PIPESTATUS[0]}` after `tee` | **yes** (`archiveToolLog`) | **yes** (`>1`, `>2`, `>1`) | `set +e` / `if` mix |
| **C** set +e wrap | `phpLint`, `markdownLinks`, `composerRequireChecker`, `twigLint`, `yamlLint` | `code=$?` after `set +e` | no | no | `set +e … set -e` around loop |
| **D** dual read-only/writable | `rector` (`runRectorConfig` fn), `phpCsFixer` | `if tool; then … else $?` | no | tool-specific dry-run codes (2, 8) | clean |
| **E** subshell guard | `runToolGuarded` (functions.inc) | `(runTool)` subshell | no | no | contains `exit` |

Divergences that matter:
- Only **B** archives logs — a phpLint/composerRequireChecker failure leaves no
  rotated artefact.
- Crash-code thresholds are per-tool magic numbers (`phpstan >1`, `phpunit >2`,
  `arkitect >1`) with duplicated crash-banner text.
- **A/D** are errexit-clean (`if` condition); **C** toggles `set +e/set -e`
  around the loop, which is where an accidental early `set -e` re-enable would
  change behaviour.
- All variants ultimately gate interactivity through `tryAgainOrAbort`, so CI
  behaviour is at least consistent — that funnel is the one thing done right
  everywhere.

**Proposed shared helper** (single source of truth, opt-in features via flags):

```bash
# runCheckStep <label> <crashThreshold> <logDir?> <logFile?> -- <cmd...>
#   - runs <cmd> under errexit-safe capture (if-condition, never set +e)
#   - reads PIPESTATUS[0] when the caller pipes to tee (detected via logDir)
#   - archives via archiveToolLog when logDir/logFile given
#   - exit > crashThreshold -> crash banner + exit 1 (no retry)
#   - exit in 1..crashThreshold -> tryAgainOrAbort <label> (retry loop)
# Read-only/dual-mode tools (rector, fixer) keep their own thin wrapper that
# calls this with --dry-run and maps dry-run codes to reportReadOnlyWouldModify.
```

Collapsing A/B/C onto this would remove ~120 lines and unify the crash-code and
log-archival policy. D stays a thin specialisation; E (aggregate subshell) is
orthogonal and stays.

**Other repetition:** the jq-tempfile-then-atomic-`mv` block recurs 5× in
`lock.inc.bash` (`startHeartbeat`, `toolStart`, `toolComplete`, `toolFailed`,
`releaseLock`) — a `jqUpdateInPlace <file> <jq-args...>` helper would fold them.

---

## 4. Dead-code inventory

| Item | Location | Status |
|---|---|---|
| PSR-4 validator body | `psr4Validate.inc.bash` | **0 bytes** — check never runs (BQ-001) |
| `psr4IgnoreList` readarray | `setConfig.inc.bash:33` | populated, never consumed |
| `phpunitAnnotations` body | `phpunitAnnotations.inc.bash` | 100% commented out (BQ-007) |
| `checkForUncommittedChanges()` | `functions.inc.bash:108-174` | defined, never called (BQ-005) |
| `phpunitReRunFailedOrFull()` | `functions.inc.bash:176-209` | defined, never called (BQ-005) |
| `toolStart/toolComplete/toolFailed()` | `lock.inc.bash:408-508` | defined, never called (BQ-006) |
| Auto-fix suggestion block | `composerRequireChecker.inc.bash` | ~60 commented lines (BQ-012) |
| TRAVIS / `phpenv config-rm` | `allTestingTools.inc.bash:16-19` | unreachable in supported CI (BQ-010) |
| `#pathsToCheck+=($binDir)` | `setPaths.inc.bash:17` | commented residue |

Roughly **300+ lines** (psr4 excluded as it's absent, not present-dead) are dead
or no-op across the tree.

---

## 5. Bash fitness — evidence only (decision deferred)

Facts for and against keeping the pipeline in bash. **This is evidence, not a
recommendation.**

- **AGAINST — silent-pass failure mode is endemic.** The two worst bugs
  (BQ-001, BQ-007) are checks that *pass by doing nothing*. Bash's "source an
  empty/commented file → success" semantics make this class trivially easy to
  ship and invisible without integration tests. A typed orchestrator would have
  surfaced an empty tool as a missing symbol.
- **AGAINST — correctness rests on a global IFS side-effect.** The whole tree's
  unquoted-array safety depends on `IFS=$'\n\t'` set 500 lines away in `bin/qa`
  (BQ-004). That is exactly the kind of spooky-action-at-a-distance bash makes
  cheap and other languages forbid.
- **AGAINST — near-zero unit testability of the orchestration.** 30 of 31 bash
  files have no test (see §6). The retry/errexit/PIPESTATUS logic — the most
  bug-prone part — is only exercisable by running the whole pipeline.
- **AGAINST — duplication with drift.** The retry loop exists in 5 variants with
  divergent crash codes and log policy (§3); the jq-update block 5×. A library
  with functions/classes would have one implementation.
- **AGAINST — bug density.** In 3,883 lines: 2 CRITICAL, 6 MAJOR, plus a long
  MINOR tail — several of which (dead interactive `git commit`, `eval` on paths)
  are latent hazards, not cosmetics.
- **FOR — the abstractions that exist are good.** `runTool`/`configPath`
  cascade, `runToolGuarded`, `archiveToolLog`, and the `branchNamePolicy` /
  `infection` / `timing` / `lock` modules are genuinely well-designed and
  documented. This is competent bash, not naive bash.
- **FOR — bash is the right *interface*.** The job is orchestrating CLIs
  (composer, phpstan.phar, phpunit, git, jq) with env vars and exit codes.
  Process-and-exit-code plumbing is bash's home turf; a rewrite still shells out
  to every one of these.
- **FOR — contributor accessibility & zero bootstrap.** No compile/runtime step;
  every consuming project already has bash. The override model (drop a
  `qaConfig/tools/x.inc.bash`) is dead-simple *because* it's `source`.
- **FOR — one file already proves bash here is testable.** `infection.inc.bash`
  is driven by `tests/Large/Infection/InfectionDiffModeTest.php`, which shells
  into the fragment with stubs and asserts the two-mode contract. The pattern
  exists; it's simply applied to 1 file of 31.
- **NEUTRAL — size is moderate, concentrated.** 3,883 lines total, but 45% lives
  in 4 files (`functions`, `lock`, `timing`, `branchNamePolicy`/`infection`).
  A rewrite would target those; the 20+ small tool wrappers are cheap either way.

---

## 6. Coverage statement

**The bash orchestration layer is effectively untested.** `tests/` contains
100 PHP files exercising the *PHP* components the pipeline shells out to
(`psr4-validate`, `package-type-check`, the sensitive-parameter scanner, the
managed-source and arkitect/infection PHP paths). Of the 31 bash files:

- **1** has a characterisation test: `infection.inc.bash` via
  `tests/Large/Infection/InfectionDiffModeTest.php` (5 test methods) — it runs
  `bash <fragment>` with a stubbed environment and asserts the diff-mode / full
  flag contract. This is the model to generalise.
- **0** others are tested. `bin/qa`, `functions.inc.bash`, `options.inc.bash`,
  `lock.inc.bash`, `timing.inc.bash`, the retry loops, and the CI/read-only
  branching have **no** unit or integration coverage.
- No `bats`, `bashunit`, or `shellcheck` gate is wired into `composer.json`,
  the `Makefile`, or `.github/workflows/` (`ci.yml`, `qa.yml`, `update-deps.yml`).
  The only `*.bash` test in the repo (`.claude/hooks-daemon/scripts/test.bash`)
  belongs to the vendored hooks-daemon and is out of scope.

Directly consequential: BQ-001 and BQ-007 (checks that silently do nothing)
would be caught by a one-line integration test asserting each `-t <tool>`
actually invokes its binary — and by adding `shellcheck` to CI, which flags the
SC2068 array bugs today.
