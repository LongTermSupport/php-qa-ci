# Fable Main-Thread Spot Checks — v1

Date: 2026-07-15. Purpose: independent hot-spot reads by the coordinating model
(bin/qa, functions.inc.bash, setPaths.inc.bash) done WHILE Phase 1 agents run,
used to (a) seed findings, (b) grade/arbitrate agent reports. Everything below
was verified directly against code by Fable, not taken from agent output.

## Verified findings

### FS-001 [MAJOR] Dead functions in functions.inc.bash (~100 lines)
- `checkForUncommittedChanges()` (functions.inc.bash:108-174) and
  `phpunitReRunFailedOrFull()` (:176-209) have ZERO callers anywhere in
  bin/, includes/, scripts/ (grep verified 2026-07-15).
- Knock-on docs rot: CLAUDE.md "Key Configuration Variables" documents
  `skipUncommittedChangesCheck` — that var is only read by the dead function.
  README likely mentions the uncommitted-changes check too (docs-core agent to
  confirm).
- Also: the dead `checkForUncommittedChanges` has an internal bug anyway (the
  's'=skip branch returns without `cd $originalDir`) — irrelevant once deleted.

### FS-002 [MAJOR] bin/qa:229-230 — `exitCode=$?` after `runTool` is dead under set -e
- `runTool "$singleToolToRun"` then `exitCode=$?`. With `set -e` active, a
  non-zero runTool aborts the script at line 229 (plain statement, not a
  condition), so line 230 can only ever capture 0. Non-aggregate single-tool
  failure path exits via errexit + EXIT trap, not via the intended
  releaseLock-with-code path. Aggregate mode masks this (runToolGuarded returns 0).
- Fix direction: `if runTool …; then exitCode=0; else exitCode=$?; fi` (the
  pattern already used inside tool fragments).

### FS-003 [MINOR] CLAUDE.md claims `scripts/phive-install.bash`; reality is `scripts/tool-install.bash`
- CLAUDE.md preflight step 8: "If phive.xml exists, runs scripts/phive-install.bash".
  bin/qa:197 runs `$qaDir/../scripts/tool-install.bash` unconditionally.
  No phive-install.bash exists in scripts/.

### FS-004 [MAJOR] CLAUDE.md pipeline description omits entire subsystems
bin/qa contains, undocumented in CLAUDE.md's "How It Works"/phases:
- locking system (lock.inc.bash, 565 lines; acquireLock/releaseLock, bin/qa:208-219)
- timing instrumentation (timing.inc.bash, 307 lines)
- read-only mode `qaReadOnly`/`QA_READONLY` (bin/qa:97-109) — documented only
  inside tool-fragment headers, not in CLAUDE.md config section
- aggregate non-fail-fast mode `qaAggregate`/`QA_FAIL_FAST` (bin/qa:111-123)
  — CLAUDE.md explicitly claims "Fail-fast design - Pipeline stops on first
  tool failure (except in retry mode)" which is now only half true
- `--json` output mode (bin/qa:14-25, fd-3 plumbing; useJsonOutput in phpstan)
- branchNamePolicy, packageType, sensitiveParameterUsage exist as tools;
  CLAUDE.md phase lists have wrong/duplicated numbering (Phase 3 numbered
  10/11/12, Phase 4 restarts at 11) and don't match the all*Tools order —
  docs-core agent to produce the authoritative diff.

### FS-005 [MINOR] bin/qa:31 fragile composer-proxy parsing
`qaDir="$(cd $binDir/$(grep -Po "[^']+php-qa-ci[^']+" $binDir/qa) && pwd)"` —
scrapes its own composer bin proxy with a regex keyed to the literal string
'php-qa-ci' inside single quotes. Breaks if composer changes proxy format or
package is forked/renamed. Unquoted expansions throughout this block.

### FS-006 [INFO] Naming: `$DIR` (options.inc.bash:2) vs `$qaDir` (bin/qa)
Two names for essentially the same anchor (includes/ dir vs bin/ dir), both
used across fragments (runTool uses $DIR). Confusing for maintainers; works.

### FS-007 [INFO] phpNoXdebug leaks `set -x` semantics + marker files
- functions.inc.bash:92-96 toggles set -x/+x around every tool invocation —
  noisy by design, but also means callers' trace state is clobbered.
- archiveToolLog creates `.warned_high_count_$$` marker files that are never
  cleaned up (one per PID per logDir, accumulates in var/qa).

### FS-008 [CRITICAL] PSR-4 validation is a silent no-op since 2023-12-18 (false green)
- includes/generic/psr4Validate.inc.bash is 0 bytes. Sourcing an empty file
  succeeds, so `runToolGuarded psr4Validate` (allLintingTools.inc.bash:7) and
  `bin/qa -t psr4` both report SUCCESS while validating nothing.
- Git forensics: commit e0240dc (2023-12-18, message "rector - working around
  using tmp for cache...") deleted all 13 lines of the fragment — an unrelated
  commit; almost certainly accidental. Prior content (verified at e8fcb9b): a
  retry loop invoking `phpNoXdebug -f "$binDir"/psr4-validate ${psr4IgnoreList[*]}`.
- The supporting machinery all still exists and pretends to be live:
  bin/psr4-validate (composer.json bin export), src/Psr4Validator.php,
  setConfig.inc.bash:32-33 loading psr4IgnoreListPath/psr4IgnoreList,
  options.inc.bash `-t psr|psr4` alias + PATH_SUPPORTING_TOOLS claiming
  "✓ Uses ${pathsToCheck[@]}", CLAUDE.md documenting it as active Phase 2 step.
- No other invocation path exists (grep: scripts/, .github/, composerScripts/ — none).
- Remediation (Phase 3): restore the fragment (modernised to the current
  runToolGuarded/errexit contract), and consider a pipeline self-check that a
  sourced tool fragment is non-empty / declares success explicitly (this bug
  class = "empty fragment sources clean").

### FS-009 [MAJOR] options.inc.bash triple-registry SSoT violation
The tool registry is maintained in THREE parallel structures that must be kept
in sync by hand: usage() text (:24-60), PATH_SUPPORTING_TOOLS /
NON_PATH_SUPPORTING_TOOLS arrays (:85-114), and the alias case map (:164-195).
The arrays even carry "VERIFIED by reading tool implementation files" comments
that are now wrong (psr4Validate marked ✓ path-supporting; it's an empty file).
Any new tool needs 3-4 hand edits; drift is structural, not accidental.

### FS-010 [CRITICAL] strict-types gate never checks .php files (find precedence bug) — Fable-found, missed by both bash agents
- phpStrictTypes.inc.bash:4-7: `find $d -name '*.php' -o -name '*.phtml' -exec grep -L 'strict_types' {} \;`
  parses as `-name '*.php' -o ( -name '*.phtml' -a -exec … )`. Because an
  -exec action is present, find suppresses the default -print for the bare
  `*.php` branch — so grep only ever runs on .phtml files.
- EMPIRICALLY VERIFIED 2026-07-15 (scratchpad repro): a `.php` file missing
  strict_types is NOT reported by the exact expression; the `\( … \)` grouped
  form correctly reports it.
- Net: the dedicated strict-types gate is a near-total no-op (modern projects
  have zero .phtml files). Third instance of the "silent-pass gate" bug class
  alongside psr4Validate (FS-008) and phpunitAnnotations.
- Mitigation in practice: PHP CS Fixer's `declare_strict_types` rule (php_cs.php)
  adds declares during writable runs, which is why the rot went unnoticed.
- Compounding (from agents, consistent with my read): interactive `read` with
  no CI guard and unguarded `sed -i` in the same fragment (BQ-002/AR-007).

### FS-011 [MAJOR] AR-001/002 derive-before-override class — VERIFIED by Fable
- Source order confirmed: `runTool setConfig` at bin/qa:162; project
  qaConfig.inc.bash sourced at bin/qa:177 (later).
- setConfig.inc.bash derivations that consume project-overridable vars BEFORE
  the override exists:
  - :69-72 `useInfection` gated on `phpUnitCoverage`/`xdebugEnabled` — a
    project setting phpUnitCoverage=0 in qaConfig leaves useInfection=1
    (stale), so infection runs and regenerates coverage the project tried to
    turn off. No runtime re-derivation safety net.
  - :81-83 `infectionMutationScoreIndicator`/`infectionCoveredCodeMSI` frozen
    at 60/80 before a project's mutationScoreIndicator/coveredCodeMSI export —
    the KNOWN instance; infection.inc.bash works around it by re-deriving at
    runtime (its own header comment documents the bug).
- Related duplication: CI detection exists twice with different logic —
  bin/qa:81-95 (explicit, incl. TTY detection) and setConfig.inc.bash:96-100
  (CLAUDECODE-only; preserves prior value via ${CI:-} so no behavioural bug,
  but two sources of truth).
- BONUS DOCS ROT (feed to docs synthesis): CLAUDE.md "Key Configuration
  Variables" claims `phpUnitCoverage=${phpUnitCoverage:-0}`; code is `:-1`
  (setConfig.inc.bash:54) — the documented default is simply wrong. Same
  section documents skipUncommittedChangesCheck (dead, FS-001).

## Positive observations (for balance)
- The newer code (detectReadOnly, runToolGuarded, qaReportAggregate,
  reportReadOnlyWouldModify, infection.inc.bash, phpArkitect.inc.bash) is
  well-commented, deliberate about errexit interactions, and documents its own
  contracts — clear quality gradient: recently-touched files are good, old
  core (functions.inc.bash interactive helpers, phpStrictTypes, phpLint,
  composerChecks) is where the rot is.
- setPaths command-substitution + `exit 1` inside `$( )` DOES propagate
  correctly under set -e (assignment exit status) — checked, not a bug.

## Arbitration notes (to apply when grading agent reports)
- php-src-map-1.md (haiku) — graded on arrival: useful inventory, but carries
  two errors to fix in synthesis: (a) summary claims "All 11 bin/ scripts are
  ... called by the QA pipeline", contradicted by its own orphan table (and by
  FS-008: psr4-validate is never invoked); (b) repeats the nonexistent
  `scripts/phive-install.bash` (docs-echo — actual file is tool-install.bash).
  Its missing catch: did not notice psr4Validate.inc.bash is empty.
  Good catches: CheckAnnotations dead-tool chain, untested composer plugins,
  no end-to-end pipeline test.
- bash-scripts-1.md (sonnet) — graded on arrival: **A-**. 22 findings, all
  evidenced; BS-001 (IFS='|||' is a char-class not a literal — verified with a
  live repro), BS-002 (deploy-skills register-then-undo window can leave
  consumer settings.json dangling if interrupted mid composer-update), BS-003/
  BS-005/BS-007 (three different overwrite-protection strategies, two of them
  "none"), BS-004 (composerScripts/installUpdateInfection.bash fully dead,
  pinned to infection 0.9.0, unverified PHAR download). Risk table for
  consumer-writing operations is exactly the Phase 3 input we need. To
  spot-verify in Phase 2: BS-001 repro claim and BS-002 phase-gating claim
  (high-impact; verify before they drive fix plans).
  → BOTH VERIFIED by Fable direct read (2026-07-15): BS-001 at
  git-hooks/pre-commit-check-vendor-uncommitted:160 (IFS='|||' + read; the
  DIRTY_REPOS block at :143-144 uses the correct %%/# idiom right above it);
  BS-002 at deploy-skills.bash:131 (copy gated on DAEMON_DETECTED==false) vs
  :214 (registration gated only on -d HOOKS_SOURCE). Findings CONFIRMED.
- bash-quality-1.md (opus) — graded: **A-**. 2 CRITICAL / 6 MAJOR / 7 MINOR /
  4 INFO; independently converged on FS-008 (psr4 no-op = BQ-001) and FS-001
  (dead functions = BQ-005). Strong: retry-loop variant catalogue (11×, 5
  variants), BQ-003 (packageType uses runTool not runToolGuarded → aborts
  aggregate mode; consistent with my allLintingTools read), test-coverage
  reality (1/31 files tested), IFS-global fragility framing. MISSED: FS-002
  (bin/qa:230 dead exit-code capture) and FS-010 (find precedence — reported
  the read/sed issues in the same file but not that .php files are never
  scanned). Verdict: accept findings; supplement with FS-002/FS-010; no full
  pass-2 needed (misses are covered by my own findings).
- architecture-1.md (opus) — graded: **A**. The AR-001/002 "derive-before-
  override" CLASS framing (infection MSI = known instance with workaround;
  useInfection/phpUnitCoverage = live staleness with NO workaround) is the
  most valuable structural insight so far; per-tool consistency matrix is the
  "disjointed" evidence the user asked for; recommends driver+metadata
  consolidation over rewrite (matches user's gut + bash-core's evidence).
  TO VERIFY in Phase 2 before fix-planning: (a) AR-002 useInfection staleness
  — trace exact source order; (b) AR-009 lock/timing dead per-tool functions
  (= BQ-006, two agents agree, still verify since it drives deletion);
  (c) phpStrictTypes-in-CI behaviour dispute: agents say "auto-skipped"; my
  analysis says it depends on errexit suspension via runToolGuarded's
  if-condition (aggregate mode: skipped silently; non-aggregate plain path:
  read EOF → errexit abort). Mostly moot given FS-010, but the errexit-
  suspension mechanics matter for the driver redesign.
- docs-rot-core-1.md (sonnet) — graded: **A-**. 17 findings (6 CRIT / 7 MAJ /
  3 MIN / 1 INFO), honest SPECULATIVE tagging, includes a "verified accurate"
  section as instructed. Fable sample-verified 3/3 CRITICALs: DC-005 (PHP 7.4
  claim vs composer.json ^8.3 — confirmed at CLAUDE.md:319 / composer.json:7),
  DC-006 (phive-install.bash nonexistent + conditional inverted — confirmed,
  = FS-003), DC-004 (= my FS-011 bonus, independently found). MISS: the
  Laravel/artisan fabrication in CLAUDE.md's Platform Detection section
  (docs-secondary caught it estate-wide; merge at synthesis).
- docs-rot-secondary-1.md (sonnet) — graded: **A**. 27 docs, per-doc verdicts,
  27 findings (8 CRIT). Laravel/artisan fabrication identified as a
  shared-origin rot CLUSTER (platform-detection.md → pipeline.md → CLAUDE.md →
  README.md) — Fable-confirmed against detectPlatform() (functions.inc.bash:6-13:
  only symfony.lock is checked; no artisan/Laravel logic exists). Also strong:
  update-deps.yml hardcoded `ref: php8.4` shipped to consumers; CI=true vs
  QA_READONLY confusion in github-actions.md; locking-system.md describing the
  dead toolStart/toolComplete/toolFailed hooks as active.
- AR-009/BQ-006 VERIFIED by Fable grep: toolStart/toolComplete/toolFailed
  defined at lock.inc.bash:408/440/479, zero call sites anywhere. Dead.
- Expect bash-core (opus) to find FS-001/FS-002/FS-007 independently; if it
  misses FS-002 (errexit-dead capture), treat the report as needing a pass 2.
- Expect docs-core to find FS-003/FS-004 numbering + missing-subsystem gaps.
