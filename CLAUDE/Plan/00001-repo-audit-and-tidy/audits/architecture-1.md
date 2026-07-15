# Architecture Audit — php-qa-ci (bash-orchestrated PHP QA pipeline)

**Auditor:** architecture agent (AR)
**Scope:** system design of the `bin/qa` pipeline and its sourced-fragment tool
runners, config cascade, cross-cutting concerns, and coupling to the Claude-Code
ecosystem layer. AUDIT-ONLY, code-derived.
**Substrate read:** `bin/qa`, `includes/options.inc.bash`,
`includes/functions.inc.bash`, `includes/generic/{setConfig,setPaths,prepareDirectories,timing,lock}.inc.bash`,
all four `all*Tools.inc.bash`, all 17 generic tool runners + 3 symfony runners,
`composer.json`, the four `src/ComposerPlugin/*`, `scripts/tool-install.bash`,
`scripts/deploy-skills.bash` (skim). Backward-compat is a hard constraint: many
consumer projects depend on tool names (`-t`), env vars, `qaConfig/` overrides
and hook scripts.

---

## 1. Architecture Map

### 1.1 Boot sequence of `bin/qa` (exact, line-referenced)

```
bin/qa
 ├─ [14–25]  early --json sniff → dup fd3=stdout, redirect stdout→stderr if --json
 ├─ [27–48]  bootstrap path discovery
 │             COMPOSER_RUNTIME_BIN_DIR set → derive qaDir/projectRoot from it
 │             else "SELF TEST" branch → resolve symlinks from BASH_SOURCE
 ├─ [49]     cd $qaDir           (cwd = library dir for the whole config phase)
 ├─ [50–54]  set -e / -u / -o pipefail ; IFS=$'\n\t'
 ├─ [63]     source includes/options.inc.bash   → parse -t/-p/--json, alias map,
 │             path-support gate, --json validation  (sets singleToolToRun,
 │             specifiedPath, useJsonOutput)
 ├─ [70–71]  phpBinPath ; source includes/functions.inc.bash  (defs only)
 ├─ [81–95]  CI auto-detect  (explicit CI / CLAUDECODE / no-TTY → CI=true)
 ├─ [101]    qaReadOnly = detectReadOnly()   (orthogonal to CI)
 ├─ [115–122] qaAggregate  (on when readonly & !QA_FAIL_FAST)
 ├─ [129]    platform = detectPlatform()     (symfony.lock → symfony else generic)
 ├─ [137–148] xdebug probe → xdebugEnabled + XDEBUG_MODE
 ├─ [155]    runTool setPaths     → testsDir/srcDir/binDir/pathsToCheck/pathsToIgnore
 ├─ [162]    runTool setConfig    → ~30 config vars + configPath resolution
 ├─ [164–168] if -p: reset pathsToCheck to the single specified path
 ├─ [177–187] source $projectConfigPath/qaConfig.inc.bash  (PROJECT OVERRIDE)  ← LATE
 ├─ [192]    cd $projectRoot      (cwd = project for the whole run phase)
 ├─ [194]    runTool prepareDirectories  → var/qa, cache, managed .gitignore block
 ├─ [197]    scripts/tool-install.bash   → verify committed PHARs, install rector
 ├─ [199–206] source qaConfig/hookPre.bash  (if present)
 ├─ [211–212] source timing.inc.bash ; lock.inc.bash
 ├─ [215–219] initLockSystem ; acquireLock ; setupLockCleanup (trap)
 ├─ [223–247] SINGLE-TOOL path: runTool $singleToolToRun ; aggregate report ; exit
 └─ FULL path:
      [255] runTool allCodingStandardsTools   (rector, phpCsFixer)
      [264] runTool allLintingTools           (psr4, composer, packageType, strict,
                                                lint, annotations, requireChecker, md)
      [272] runTool allStaticAnalysisTools    (branchNamePolicy, phpstan, arkitect, spu)
      [280] runTool allTestingTools           (phpunit, infection)
      [284] qaReportAggregate → exit 1 if any failed
      [290–314] ALL-TESTS-PASSING banner ; runTool phploc
      [317–324] source qaConfig/hookPost.bash
      [326–344] retry warning ; releaseLock 0
```

Two `cd`s split the run into a **config phase** (cwd=`qaDir`, so
`./../configDefaults` and `./../includes` resolve) and a **run phase**
(cwd=`projectRoot`). This is load-bearing and undocumented in-file.

### 1.2 Module inventory

| Module | Kind | Responsibility |
|---|---|---|
| `bin/qa` | orchestrator | boot, phase sequencing, lock lifecycle, single-tool dispatch |
| `options.inc.bash` | parser | `-t`/`-p`/`--json`, alias→canonical map, **hardcoded** path-support gate |
| `functions.inc.bash` | shared lib | `runTool`, `configPath`, `phpNoXdebug`, retry/aggregate/read-only helpers, `archiveToolLog`, dir finders |
| `setPaths.inc.bash` | config | testsDir/srcDir/binDir + pathsToCheck/Ignore |
| `setConfig.inc.bash` | config | ~30 tool vars, `configPath` resolution, CI re-derive |
| `prepareDirectories.inc.bash` | config | var/qa scaffolding, managed root `.gitignore` block |
| `timing.inc.bash` | infra | ETA / historical timing (JSON store) |
| `lock.inc.bash` | infra | single-run lock, heartbeat, master-log tee, per-tool tracking (**dead**) |
| `all*Tools.inc.bash` (×4) | phase groups | `runToolGuarded` sequences |
| `includes/generic/<tool>.inc.bash` (×17) | leaf runners | one tool each |
| `includes/symfony/*` | platform overlay | setConfig extend, twig/yaml lint, allLinting extend |
| `src/ComposerPlugin/*` (×4) | composer hooks | phive/rector install, skills deploy, phpstan guard, managed-source deploy |
| `src/PHPStan/Rules/*` (~30) | PHP | the actual custom static-analysis rules (shipped via `rules-default.neon`) |

### 1.3 Sourced-fragment execution model (the real contract)

Every tool runner is **`source`d** into the running shell by `runTool`
(`functions.inc.bash:20`), not executed as a subprocess. There is **one shared
mutable global namespace** across ~30 files. The implicit contract a runner must
honour — nowhere written down — is:

- **May read** (set upstream): `projectRoot qaDir binDir srcDir testsDir
  pathsToCheck pathsToIgnore specifiedPath CI qaReadOnly qaAggregate
  xdebugEnabled phpBinPath pharDir varDir cacheDir standardIFS DIR platform`
  plus its own `<tool>ConfigPath` and feature flags.
- **May set / mutate**: `hasBeenRestarted` (retry signal read by `bin/qa:326`),
  `qaFailedTools` (via `runToolGuarded`), and any number of un-namespaced locals
  that leak into the global scope (e.g. `phpStanExitCode`, `csFixerExitCode`,
  `rectorBin`, `testSummary`). Nothing is `local`; a variable set by one tool is
  visible to the next.
- **Termination**: a runner ends the *whole run* with `exit 1` on hard failure,
  or `return 0`/falls off the end on success. `tryAgainOrAbort` **`exit 1`s in
  CI**. This is why aggregate mode has to wrap each tool in a **subshell**
  (`runToolGuarded`, `functions.inc.bash:351`) — to contain the `exit`.
- **errexit expectations**: `bin/qa` runs under `set -e -u -o pipefail`. Runners
  that want to inspect a non-zero exit must either wrap the call in an `if`
  condition (errexit is suspended there) or bracket with `set +e … set -e`. Both
  idioms are used, inconsistently (see §3 AR-004).

**Contract violations found** (detail in §3): `psr4Validate` is empty;
`phpunitAnnotations` is fully commented; `phpStrictTypes` uses interactive
`read` with no CI guard and `sed -i` with no read-only guard; `branchNamePolicy`
uses a *different* function-wrapped idiom than every other runner; `composerChecks`
mutates `composer.json` (normalize) ignoring read-only mode.

### 1.4 `runTool` resolution order

`runTool <name>` (`functions.inc.bash:20`) sources the **first** of:
1. `$projectConfigPath/tools/<name>.inc.bash` (project override)
2. `$DIR/../includes/$platform/<name>.inc.bash` (platform)
3. `$DIR/../includes/generic/<name>.inc.bash` (generic)

`runNonPlatformTool` skips step 2 (used by `symfony/allLintingTools` to reach the
generic aggregate). `configPath <rel>` (`functions.inc.bash:66`) resolves config
*files* in the parallel order project→platform→generic.

### 1.5 Config cascade

| Layer | Source | When (relative to override) |
|---|---|---|
| 1. Built-in defaults | `setConfig.inc.bash` `${x:-default}` | **before** override |
| 2. Platform defaults | `includes/symfony/setConfig.inc.bash` (wraps generic) | before override |
| 3. Tool config files | `configDefaults/{generic,symfony}/*` via `configPath` | resolved before override |
| 4. Project var overrides | `qaConfig/qaConfig.inc.bash` | **`bin/qa:177` — AFTER setConfig** |
| 5. Project config files | `qaConfig/*` via `configPath` | wins at file-resolution time |
| 6. Project tool overrides | `qaConfig/tools/<tool>.inc.bash` | wins at `runTool` time |
| 7. Env vars | ambient | seed layer 1 via `${x:-}` |

Layers = **7**. The ordering hazard is that layer-1 *derivations* are computed at
`bin/qa:162`, but the project's own values (layer 4) are not sourced until
`bin/qa:177`. Any derived, **renamed** variable freezes at the default (§2).

---

## 2. Configuration ordering findings (the “disjointed” root cause)

### AR-001 (High) — Derived-before-override renamed vars (a *class* of bug)
`setConfig` runs (`bin/qa:162`) **before** `qaConfig.inc.bash` is sourced
(`bin/qa:177`). Any variable that `setConfig` derives into a **different name**
from a project-settable var is frozen at the generic default:

- `infectionMutationScoreIndicator=${mutationScoreIndicator:-60}` (`setConfig:81`)
- `infectionCoveredCodeMSI=${coveredCodeMSI:-80}` (`setConfig:83`)

`infection.inc.bash` documents and works around exactly this by re-deriving at
run time: `minMsi="${mutationScoreIndicator:-${infectionMutationScoreIndicator}}"`.
The workaround masks the bug for infection only; the pattern is fragile and any
future `foo=${projectVar:-…}` in `setConfig` will silently ignore the project.

### AR-002 (High) — `useInfection` / `phpUnitCoverage` staleness (same class, unguarded)
`setConfig` computes `useInfection` (`:68–72`) from `phpUnitCoverage`/`xdebugEnabled`
**before** the override. A project that sets `phpUnitCoverage=0` (or `useInfection`)
in `qaConfig.inc.bash` changes the input **after** the decision is frozen —
`useInfection` keeps its pre-override value. Unlike MSI, nothing re-derives this
downstream, so the mismatch is live. Same root cause as AR-001, no safety net.

### AR-003 (Medium) — CI detected/derived in three places
CI is set in `bin/qa:81–95` (explicit/CLAUDECODE/no-TTY), **re-derived** in
`setConfig:96–100` (CLAUDECODE again), and read by `functions.inc.bash`
(`tryAgainOrAbort`, `checkForUncommittedChanges`, …) and `phpunit.inc.bash`
(`${CI:-'false'}`). Three detectors for one concept; the `setConfig` copy is
redundant given `bin/qa` already set it, and drift between them would change
prompting behaviour.

### AR-013 (Low) — Documented default drift
`CLAUDE.md` states `phpUnitCoverage=${phpUnitCoverage:-0}`; the code is
`:-1` (`setConfig:54`). Coverage is therefore ON by default (then zeroed if no
xdebug), contradicting the documentation consumers read.

---

## 3. Tool consistency matrix (rows=runners, cols=cross-cutting concerns)

Legend: ✓ has it · ✗ lacks it · — N/A · ⚠ present but broken/partial.
“Retry” = `tryAgainOrAbort`/while loop. “CI-safe” = does not block on a TTY.
“R/O” = honours `qaReadOnly`. “Logs” = uses `archiveToolLog`. “-p” = reads
`pathsToCheck`. “Cfg” = overridable via `configPath`/`qaConfig`.

| Runner | Retry | CI-safe | R/O | Logs | -p | JSON | Cfg | errexit idiom |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|---|
| rector | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ✓ | if-cond capture |
| phpCsFixer | ✓ | ✓ | ✓ | ⚠ writes log, no rotation | ✓ | ✗ | ✓ | if-cond capture |
| psr4Validate | ✗ | — | — | ✗ | ⚠ listed, unused | ✗ | ✓ | **EMPTY FILE** |
| composerChecks | ✗ | ✓ | ✗ **mutates** | ✗ | ✗ | ✗ | ✗ | set +e/set -e |
| packageType | ✓ | ✓ | — | ✗ | ✗ | ✗ | ✗ | if-cond capture |
| phpStrictTypes | ✗ | ✗ **`read`** | ✗ **`sed -i`** | ✗ | ✓ | ✗ | ✗ | none |
| phpLint | ✓ | ✓ | — | ✗ | ✓ | ✗ | ✗ | set +e/set -e |
| phpunitAnnotations | — | — | — | ✗ | ✗ | ✗ | ✗ | **ALL COMMENTED** |
| composerRequireChecker | ✓ | ✓ | — | ✗ | ✗ | ✗ | ✓ | set +e/set -e |
| markdownLinks | ✓ | ✓ | — | ✗ | ✗ hardcoded | ✗ | ✗ | set +e/set -e |
| branchNamePolicy | ✗ | ✓ | — | ✗ | ✗ | ✗ | ✓ yaml | **fn-wrapped `_bnp_run`** |
| phpstan | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ | tee + PIPESTATUS |
| phpArkitect | ✓ | ✓ | — | ✓ | ✗ config-internal | ✗ | ✓ | tee + PIPESTATUS |
| sensitiveParameterUsage | ✓ | ✓ | — | ✗ | ✗ | ✗ | ✗ env | if-cond capture |
| phpunit | ✓ | ✓ | — | ✓ ×2 | ✓ | ✗ | ✓ | set +e/set -x |
| infection | ✓ | ✓ | — refuses dirty tree | ✗ consumes | ⚠ diff-filter | ✗ | ✓ | if-cond capture |
| phploc | ✗ | — | — | ✗ | ✓ | ✗ | ✗ | none |
| twigLint (sf) | ✓ | ✓ | — | ✗ | ⚠ twigDirectories | ✗ | ✗ | set +e/set -e |
| yamlLint (sf) | ✓ | ✓ | — | ✗ | ⚠ yamlDirectories | ✗ | ✗ | set +e/set -e |

**What the matrix shows:** every column is a patchwork. Five distinct errexit
idioms; log archival in 3 of 19; read-only in 2 of 19 (with 2 more that *should*
have it and silently mutate); JSON in 1; per-tool timing in 0. There is no shared
driver — each runner re-implements the loop, the exit-code capture, the CI check,
and (sometimes) the logging by hand. This *is* the “clunky and disjointed”
experience, expressed as code.

### Contract-violation / dead-code findings

**AR-004 (Medium) — Five errexit idioms, no shared driver.** if-condition
capture (rector, packageType, spu, infection), `set +e/set -e` (composerChecks,
phpLint, requireChecker, markdownLinks, twig/yaml), `tee`+`PIPESTATUS`
(phpstan, arkitect), `set +e/set -x` (phpunit), and function-wrapped-return
(branchNamePolicy). Each is a chance to get errexit wrong; the divergence is pure
accident of authorship, not design.

**AR-005 (High) — `psr4Validate.inc.bash` is a 0-byte no-op.** `runToolGuarded
psr4Validate` (`allLintingTools:7`) sources an empty file, so **PSR-4 validation
never runs**, despite `bin/psr4-validate` existing, being in `composer.json`
`bin`, being listed as a PATH_SUPPORTING_TOOL (`options.inc.bash:90`), and being
documented as an active phase in `CLAUDE.md`. Silent loss of a gate across every
consumer.

**AR-006 (High) — `phpunitAnnotations.inc.bash` is entirely commented out.**
Same shape as AR-005: the runner is sourced by `allLintingTools:44`, prints its
banner, and does nothing. `bin/phpunit-check-annotation` and
`src/PHPUnit/CheckAnnotations.php` exist but are never invoked.

**AR-007 (High) — `phpStrictTypes` ignores both CI and read-only.** It calls
`read -p "Would you like to fix?"` with **no `CI` guard** (`phpStrictTypes.inc.bash:11`)
and mutates via `sed -i` (`:14`) with **no `qaReadOnly` guard**. In a read-only
CI run it either passes vacuously (all files already declare strict types) or
aborts under errexit on the first `read` EOF — never the intended “report the
missing files” behaviour. It is also a mutating tool that bypasses the read-only
contract rector/fixer carefully honour, and it uses forbidden `sed -i`.

**AR-008 (Medium) — `composerChecks` mutates in read-only runs.** `composer
normalize` (`composerChecks.inc.bash`) rewrites `composer.json`, and `composer
dump-autoload` rewrites generated autoload files, with **no `qaReadOnly` gate**.
A read-only verification run (the CI gate) can therefore still change tracked
files — the exact leak read-only mode exists to prevent.

**AR-009 (Medium) — Per-tool lock/timing tracking is dead code.**
`toolStart`/`toolComplete`/`toolFailed` (`lock.inc.bash:408–508`) are defined and
never called (verified by grep). The lock file’s `current_tool` and `tools[]`
stay empty for the whole run. Worse, `recordCommandTiming` (`lock.inc.bash:538`)
keys on the lock file’s `tool` field, which is **empty for a full pipeline run**
→ `if [[ -n "$tool" ]]` is false → **full-run timings are never recorded**; only
`-t <single>` runs feed the ETA store. The ETA feature is half-wired.

**AR-010 (Medium) — Path-support list is a hand-maintained duplicate.**
`options.inc.bash:85–114` hardcodes `PATH_SUPPORTING_TOOLS` /
`NON_PATH_SUPPORTING_TOOLS` “VERIFIED by reading tool implementation files”. This
is a manual mirror of behaviour that lives in the runners — already drifted:
`psr4Validate` is listed as path-supporting but is empty (AR-005), and
`phpunitAnnotations` is listed as non-path but is commented (AR-006). Any new
tool or path-behaviour change must be edited in two places or the gate lies.

**AR-011 (Low) — phpunit coverage path bypasses the memory limit.** In coverage
mode phpunit uses `$phpBinPath` directly (`phpunit.inc.bash`, `phpCmd="$phpBinPath"`)
instead of `phpNoXdebug`, so `phpqaMemoryLimit` (applied only inside
`phpNoXdebug`, `functions.inc.bash:94`) is **not** applied to the heaviest run.

**AR-012 (Low) — Unnamespaced globals leak between sourced tools.** No runner
declares `local`; e.g. `phpStanExitCode`, `csFixerExitCode`, `rectorBin`,
`testSummary`, `extraConfigs` persist in the shared scope after their tool
returns. No bug observed today, but it is an aliasing hazard intrinsic to the
source-everything model.

---

## 4. Cross-cutting concerns — where each lives, who opted in

| Concern | Home | Adopters | Gaps |
|---|---|---|---|
| Read-only (`--dry-run`) | `detectReadOnly`, `reportReadOnlyWouldModify` (functions) | rector, phpCsFixer | **composerChecks, phpStrictTypes mutate anyway** (AR-007/8) |
| Retry / abort | `tryAgainOrAbort` (functions) | 12 of 19 runners | branchNamePolicy, composerChecks, phpStrictTypes, phploc, dead ones opt out |
| Aggregate (collect failures) | `runToolGuarded`/`qaReportAggregate` | the 4 `all*Tools` groups | single-tool path only aggregates for meta-targets |
| Log archival / rotation | `archiveToolLog` (functions) | phpstan, phpArkitect, phpunit | phpCsFixer logs but never rotates; rest don’t log |
| Xdebug toggling | `phpNoXdebug` wrapper + `XDEBUG_MODE` | global; phpunit/infection special-case | coverage phpunit bypasses wrapper (AR-011) |
| Memory limit | `phpNoXdebug -d memory_limit` | everything through the wrapper | coverage phpunit bypasses it (AR-011) |
| Path filtering | `pathsToCheck` global + `options` gate | 8 runners read it | duplicated, drifted gate (AR-010) |
| Timing / ETA | `timing.inc.bash` + lock | acquire/release only | per-tool dead; full-run never recorded (AR-009) |
| Locking / heartbeat | `lock.inc.bash` | `bin/qa` lifecycle | per-tool tracking dead (AR-009) |
| JSON output | `useJsonOutput` + fd3 | phpstan only | validated-exclusive in options |

The takeaway: each cross-cutting concern has a **home in `functions.inc.bash`**,
but adoption is opt-in per runner and mostly partial. The infrastructure to be
consistent already exists; it simply isn’t applied uniformly.

---

## 5. Coupling to the Claude-Code ecosystem

The QA pipeline (`bin/qa` + runners + `configDefaults` + `src/PHPStan/Rules`) is
**logically separable** from the Claude layer, but they are **packaged and
triggered together**:

- `composer.json` `extra.class` registers **four** plugins on one package. Two
  are QA-intrinsic (`PhiveUpdatePlugin` installs the tool PHARs/rector;
  `PhpStanGuardPlugin` guards the phpstan version). **Two are pure
  Claude-ecosystem**: `SkillsDeployPlugin` shells out to
  `scripts/deploy-skills.bash` to write `.claude/skills|agents|hooks`, and
  `ManagedSourceDeployPlugin` regenerates a consumer’s `PhpQaCi\` source tree.
- All four subscribe to the **same** `POST_INSTALL_CMD`/`POST_UPDATE_CMD`
  (`SkillsDeployPlugin:45`, `PhpStanGuardPlugin:53` at priority -10,
  `PhiveUpdatePlugin:47`), and `composer.json:scripts` *also* runs
  `tool-install.bash` on those events — so a consumer’s `composer install`
  fires the QA tool install **and** a Claude config push **and** a managed-source
  codegen in one go. Skills deploy is opt-out only via
  `PHP_QA_CI_DISABLE_CONFIG_PUSH`.
- The pipeline **runtime** does not depend on the Claude layer: `bin/qa` never
  calls skills/agents/hooks, and only reads `CLAUDECODE` as a CI signal
  (`bin/qa:84`). Coupling is entirely at **install time**, through shared
  composer events and a shared package boundary.

**Verdict:** clean at runtime, entangled at packaging/install. A consumer that
wants only the QA gate cannot take it without also accepting the Claude config
push (short of an env flag). This is a separability smell, not a runtime
dependency — addressable by splitting packages or gating the Claude plugins
behind explicit opt-in (§6, move 8).

---

## 6. Improvement directions (ranked by value ÷ risk; each backward-compatible)

**1. Formalise the tool-runner contract → declarative metadata + one shared
driver.** *(highest value)* Give each tool a small manifest (name, aliases,
`mutates`, `supportsPaths`, `logDir`, `jsonCapable`, `binOrPhar`, `configFile`)
and a single `runToolDriven` that provides the retry loop, exit-code capture,
CI/read-only handling, log archival and JSON plumbing **once**. Runners shrink to
“build the command + interpret the exit code”. *Compat:* tool names, `-t`
aliases, env vars and `qaConfig/tools/*.inc.bash` overrides are unchanged; the
driver is additive and a project override still wins because it is sourced first.

**2. Fix the config-ordering class (AR-001/002).** Source
`qaConfig.inc.bash` **before** `setConfig` derives dependent values, or split
`setConfig` into “seed defaults” (early) and “derive” (after the override), or
make every derived value lazy at point-of-use as infection already does. *Compat:*
identical variable names and defaults; only the *timing* of derivation changes,
which can only *fix* projects currently ignored.

**3. Restore the silently-dead gates (AR-005/006).** Wire `psr4Validate` to
`bin/psr4-validate` and re-enable `phpunitAnnotations` (or formally remove both
from the sequence, options list and docs). *Compat:* these are advertised as
active, so restoring them matches documented behaviour; stage behind a flag
(`usePsr4Validate=${…:-1}`) if a consumer’s tree would newly fail.

**4. Extend read-only to every mutating tool (AR-007/008).** Route
`phpStrictTypes` and `composer normalize`/`dump-autoload` through the same
`qaReadOnly` check + `reportReadOnlyWouldModify` that rector/fixer use; replace
`phpStrictTypes`’ interactive `read`/`sed -i` with a CI-safe, driver-provided
apply/report path. *Compat:* default writable mode behaves as today; only the CI
read-only gate becomes honest.

**5. Single source of truth for path support (AR-010).** Derive the
`options.inc.bash` gate from the per-tool metadata (move 1) instead of the
hand-maintained arrays. *Compat:* same user-facing errors, no drift.

**6. Uniform log archival + JSON via the driver.** Every runner that produces
output gets `archiveToolLog` rotation and, where the tool supports it, a
`--json` path — for free from the driver. *Compat:* additive; existing log
locations preserved.

**7. Finish or delete the timing/lock per-tool feature (AR-009).** Either call
`toolStart/Complete/Failed` from the driver and fix full-run timing to key on the
invocation, or remove the dead functions and the unused `tools[]`/`current_tool`
fields. *Compat:* lock-file JSON is runtime-only and gitignored — no consumer
contract to preserve.

**8. Separate the Claude-ecosystem plugins from the QA gate (§5).** Move
`SkillsDeployPlugin` + `ManagedSourceDeployPlugin` (and `scripts/deploy-skills`)
into a companion package, or keep them but make the config push **opt-in** rather
than opt-out. *Compat:* preserve `PHP_QA_CI_DISABLE_CONFIG_PUSH`; ship a
metapackage so existing single-require consumers are unaffected.

**9. Collapse CI detection to one place (AR-003).** Detect CI once in `bin/qa`,
export it, and delete the `setConfig` re-derivation. *Compat:* same resulting
value; removes a drift source.

**10. Namespace runner locals (AR-012).** As runners migrate to the driver,
declare their working vars `local` (functions) or prefix them. *Compat:* internal
only.

---

## 7. Is bash still a reasonable substrate for *this* architecture?

**Evidence it strains:**
- The retry/read-only/aggregate machinery already fights the language: aggregate
  mode needs a **subshell** solely to contain a runner’s `exit`
  (`functions.inc.bash:351`); five different errexit idioms exist because
  `set -e` interacts unpredictably with pipelines and conditions (AR-004).
- State is a single mutable global namespace across ~30 sourced files with no
  `local` discipline (AR-012) — the classic bash scaling failure.
- Real logic is already **escaping bash into PHP** (`Psr4Validator`,
  `CheckAnnotations`, `ExplicitPackageTypeCheck`, `SensitiveParameterUsageScanner`,
  ~30 PHPStan rules) and into **`jq`** for all lock/timing JSON. Bash is
  increasingly just a launcher around PHP/jq.
- The config cascade’s ordering bug (AR-001/002) is a direct consequence of
  imperative top-to-bottom sourcing with no declarative resolution phase.

**Evidence it still fits:**
- The core job is genuinely “run external binaries in order, in the right cwd,
  with env vars” — bash’s home turf; `phpNoXdebug` and `configPath` are concise.
- PHARs + `phpNoXdebug` process control is natural in shell and awkward in PHP.
- Consumers already depend on the bash surface (env vars, `qaConfig/*.inc.bash`
  hooks) — a rewrite breaks that contract.

**Reading:** bash is defensible as the *launcher*, but the pipeline has outgrown
bash as the place to express **cross-cutting policy** (retry, read-only, logging,
metadata). The highest-leverage move is not a language change but move 1 — pull
policy into a single driver (still bash) fed by declarative metadata, and keep
pushing real logic into the already-growing PHP layer. A full rewrite is high
risk against the consumer contract and is not indicated by the evidence; a
driver-and-metadata consolidation captures most of the benefit at low risk.

---

## 8. Coverage statement

Read in full: `bin/qa`, `options.inc.bash`, `functions.inc.bash`,
`setConfig.inc.bash`, `setPaths.inc.bash`, `prepareDirectories.inc.bash`,
`timing.inc.bash`, `lock.inc.bash`, all four `all*Tools.inc.bash`, all 17 generic
runners (rector, phpCsFixer, psr4Validate, composerChecks, packageType,
phpStrictTypes, phpLint, phpunitAnnotations, composerRequireChecker,
markdownLinks, branchNamePolicy, phpstan, phpArkitect, sensitiveParameterUsage,
phpunit, infection, phploc), all three symfony runners + symfony setConfig,
`composer.json`, `scripts/tool-install.bash`, `SkillsDeployPlugin`,
`PhpStanGuardPlugin`, `PhiveUpdatePlugin` (partial). Skimmed: `scripts/deploy-skills.bash`
(header + role), `ManagedSourceDeployPlugin` (role via composer.json + §5),
`src/` inventory (listed, not each rule read). Dynamic verification: grepped for
`toolStart/Complete/Failed`, `recordCommandTiming`, `calculateEta` callers;
confirmed `psr4Validate` (0 bytes) and `phpunitAnnotations` (all-comment) no-ops;
confirmed `phpUnitCoverage` default drift vs `CLAUDE.md`. **Not covered:**
runtime execution of the pipeline (static reading only); the ~30 PHPStan rule
bodies; `parse-junit-logs.py`; the setup/CI scripts beyond `tool-install.bash`.
Findings AR-001…AR-013 are code-anchored; severities are the auditor’s judgement.
