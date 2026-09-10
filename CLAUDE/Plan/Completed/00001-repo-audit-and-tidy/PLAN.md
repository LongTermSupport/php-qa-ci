# Plan 00001 — Repo Audit & Tidy (php-qa-ci)

**Status**: Complete (Phases 1-6 delivered through 2173b0a, 2ba9a81, 49b93f9; the remaining Bash-era findings were superseded when Plan 00003 replaced the Bash pipeline with PHP)
**Mode**: IMPLEMENTATION — user authorised 2026-07-15 ("do the job, do it
properly, no fucking about"). No warn→enforce staging: restored gates enforce
immediately; consumer CI failing on real violations is the product working.
**Branch**: php8.4
**Created**: 2026-07-15

## Mission

This repo is legacy-heavy but highly useful and consumed by multiple projects.
Seriously tidy it up **without breaking anything**. Three problem axes:

1. **Docs rot** — hallucination, inaccuracies, drift between docs and code
   (CLAUDE.md, README.md, docs/, CLAUDE/, templates).
2. **Bash code quality** — the pipeline is bash (~5.5k lines). Working
   hypothesis (user's gut + to be validated): **keep bash**, but raise the
   quality bar significantly.
3. **Architecture cohesion** — the way tools are orchestrated feels clunky and
   disjointed; config cascade, tool-runner system, per-tool inconsistencies.

## Operating model (economics)

- Fable (main thread): coordination, arbitrage, final decisions, hot-spot
  spot-checks, targeted deep dives via fable subagents only when necessary.
- Haiku/Sonnet: exploration, inventory, claim-verification legwork.
- Opus/Sonnet: first-line analysis; Opus for first-line review.
- **All findings MUST be persisted to files in this plan folder.** Context is
  never the storage mechanism. Audit files are semantically versioned
  (`<topic>-1.md`, `<topic>-2.md`, …) to support looped audit → polish cycles.

## Folder layout

```
00001-repo-audit-and-tidy/
├── PLAN.md               — this file (tracking, decisions, status)
├── audits/               — raw audit reports, one per axis, versioned -N.md
│   ├── docs-rot-core-1.md        (CLAUDE.md + README.md claim verification)
│   ├── docs-rot-secondary-1.md   (docs/, CLAUDE/, templates, qaConfig READMEs)
│   ├── bash-quality-1.md         (bin/qa, includes/**, style + correctness)
│   ├── bash-scripts-1.md         (scripts/*.bash, ci.bash, git-hooks)
│   ├── architecture-1.md         (orchestration model, config cascade, cohesion)
│   └── php-src-map-1.md          (src/ + bin/ PHP entry points, quick map)
├── synthesis/            — Fable-authored: verdicts, arbitrage, decisions
└── fix-plans/            — per-axis remediation plans (Phase 2, after audit)
```

## Repo facts (baseline, verified 2026-07-15)

- Bash pipeline: 5,566 lines across bin/qa (352), includes/ (~3.2k), scripts/ (~1.7k), ci.bash.
  Biggest files: scripts/deploy-skills.bash 705, includes/functions.inc.bash 582,
  includes/generic/lock.inc.bash 565, includes/generic/timing.inc.bash 307.
- Project docs: ~5.8k lines of markdown (excl. vendor/.claude/tools/test assets).
  Biggest: CLAUDE/Plan/skills-deployment-system-2025-11.md 1129, CLAUDE.md 1106,
  CLAUDE/Plan/locking-system.md 1083, README.md 473.
- PHP src/: ComposerPlugin, ManagedSource, Markdown, PHPStan, PHPUnit,
  PackageType, Psr4Validator, SensitiveParameter, Helper, Constants.
- includes/generic/psr4Validate.inc.bash is **0 lines** (empty file) — flagged
  immediately as suspicious; CLAUDE.md documents it as an active pipeline step.

## Phases & task board

### Phase 1 — Parallel audits (agents write directly to audits/)

- ✅ T1.1 Docs-rot audit: CLAUDE.md + README.md → audits/docs-rot-core-1.md (sonnet; graded A-, 6 CRIT; sample-verified 3/3)
- ✅ T1.2 Docs-rot audit: docs/, CLAUDE/*.md, templates, misc READMEs → audits/docs-rot-secondary-1.md (sonnet; graded A, 8 CRIT; Laravel fabrication cluster confirmed)
- ✅ T1.3 Bash quality audit: bin/qa + includes/** → audits/bash-quality-1.md (opus; graded A-, 2 CRIT / 6 MAJOR; missed FS-002 & FS-010 — covered by Fable)
- ✅ T1.4 Bash quality audit: scripts/, ci.bash, git-hooks, composerScripts → audits/bash-scripts-1.md (sonnet; graded A-, 22 findings, 0 CRITICAL / 8 MAJOR)
- ✅ T1.5 Architecture/cohesion audit → audits/architecture-1.md (opus; graded A; key insight: derive-before-override config-ordering bug CLASS; recommends driver+metadata consolidation over rewrite)
- ✅ T1.6 PHP src map + bin entry-point inventory → audits/php-src-map-1.md (haiku; graded — see synthesis/fable-spot-checks-1.md arbitration notes)

### Phase 2 — Fable synthesis & arbitrage

- ✅ T2.1 Spot-check hot spots (Fable) → synthesis/fable-spot-checks-1.md; FS-001..FS-011 incl. 2 Fable-only CRITICALs (FS-008 root cause, FS-010 find-precedence)
- ✅ T2.2 Verdict: KEEP BASH with 6 mandatory conditions (C1-C6) → synthesis/bash-verdict-1.md (Fable; decisive argument: the consumer extension API *is* bash — qaConfig fragments are a public contract)
- ✅ T2.3 Cross-audit synthesis → synthesis/findings-master-1.md (opus agent; 84 unique findings: 3 CRIT / 29 MAJOR / 30 MINOR / 13 INFO / 3 SPEC; persisted by coordinator — daemon policy blocks subagent report-writes)
- 🔄 T2.4 Verification: BS-001/BS-002, AR-001/002, AR-009/BQ-006, DC-004/005/006, Laravel cluster all Fable-verified; remaining docs findings accepted on 3/3 sample — per-item re-verify during fix execution

### Phase 3 — Remediation planning (still no code changes)

- ✅ T3.1 fix-plans/docs-rot-fixes-1.md (sonnet) — 14 WPs, target docs architecture (SSoT split), DANGER code-decision dependency list
- ✅ T3.2 fix-plans/bash-refactor-1.md (opus) — WP-B0..B5, DAG, release slicing; recs: PHPUnit-shells-out harness, RETIRE annotations gate, WIRE timing via driver
- ✅ T3.3 fix-plans/consumer-scripts-1.md (opus) — quick wins front-loaded, artefact ownership model, deploy-skills decomposition sequenced last
- ✅ T3.4 fix-plans/risk-and-verification-1.md (Fable) — R0 "waking the dead gates" staged warn→enforce policy (binding), 8-risk register, V1-V6 verification, cross-plan sequencing, Q-1..Q-6 user decision list
- ❌ T3.5 Audit-polish loop CANCELLED — user ruling made it moot; reviewers stopped before writing output. risk-and-verification-2.md supersedes v1 (R0 staging deleted, Q-1..Q-6 resolved).

### Phase 4 — Implementation (authorised 2026-07-15)

- ✅ T4.1 W1 Correctness: committed 5f3f409 — psr4 gate restored (M-001), strict-types rewritten (M-003/M-004), annotations gate retired (M-002), M-009 deriveDependentConfig, M-012, M-013, M-014, M-022/M-043, M-024; gate-liveness test added; verified end-to-end (bin/qa -t psr4 / -t st green; planted violations fail; 149 Small tests green)
- ✅ T4.2 W2 Dead code & hygiene: committed 689aa22 by the w2 agent — M-023 (dead lock hooks deleted + LIVE fix: releaseLock's `[[ -n "$tool" ]]` guard froze full-suite ETA forever; key agreement `:full-suite` Fable-verified), M-044/045/046 deletions, M-025 quoting (incl. mandatory extraConfigs/paratestConfig restructuring), M-026 eval removal, M-027 composerChecks read-only (reportReadOnlyWouldModify exits 1 — verified), M-047/048/049/051/075, shellcheck -S error CI job. Fable grade: A — every hunk spot-checked; independent re-verify: 149/149 Small tests, local shellcheck gate exit 0, zero callers of deleted hooks, setPaths quoting safe (findTestsDir/findSrcDir hard-exit on missing dirs)
- ✅ T4.3 W3 Docs rot: committed e9ef777 — every claim re-verified against live code at edit time (caught the mid-flight PHP ^8.4 bump and W4's update-deps fix, avoiding rot-2.0); Laravel fabrication + configDefaults.inc.bash + fake branch-fallback list deleted; 2 dead files removed. Fable grade: A — verified counts sampled against source (PHIVE=5 ✅, always-on PHPStan rules=14 ✅ [10 rules + 4 tagged services]); confirmed no doc claims went stale against the post-W3 f812b82 ownership exceptions; mdlinks gate exit 0
- ✅ T4.4 W4 Consumer scripts: main commit 774e218 + follow-up f812b82 (Fable-landed after reconciling a concurrent-edit collision with the w4 agent — both implemented the flag rulings simultaneously). Final state: install_signed (marker-gated git hook, foreign hooks warned + preserved) and install_seed_once (workflow seed-once) in consumer-write.inc.bash SSoT; behaviours smoke-tested directly. Main commit was 774e218 landed (M-007/015-021/060/063-070/073; branch-protection GET→merge→PUT Fable-verified — preserves foreign contexts + restrictions, normalises GET/PUT schema; consumer-write.inc.bash ownership SSoT). Fable grade: A-. Follow-up commit pending on two Fable rulings: (1) git pre-commit hook = ours-or-absent overwrite via PHP-QA-CI-HOOK-SIGNATURE marker grep, foreign hook → warn + refuse (singleton path, not ours to clobber); (2) qa.yml workflow = SEED-ONCE (docs contract says consumers customize it; qaConfig/ can't express matrix/triggers)
- ✅ T4.5 W5 Structural: commits 2c493b7 (characterisation golden-master, proven green pre-refactor) → d824341 (registry SSoT: toolRegistry.inc.bash, options/phase files derive from it) → f421b8e (qaSimpleTool driver; 5 homogeneous fragments migrated, 9 bespoke deliberately not) → 9836f21 (M-053 fork-tolerant proxy self-parse + notes). Fable grade: A — gate model verified exact against pre-refactor phase files (phpstan=notQuick; phpunit+infection quick-gated, infection also useInfection; others unconditional); M-053 design verified ($qaDir stays library bin/, loud failure); independent re-verify: 209 Small tests green, shellcheck gate clean, psr4/st/lint smokes exit 0, bogus -t rejected, path gating intact. RULING: M-071/M-072 stay deferred to a scripts follow-up (gated on unbuilt WP-S3 helper + WP-S7 golden fixture; W4 closed) — do NOT force them blind. Doc-staleness handoff checked: no doc referenced the phase files/options internals; one stale code comment (phpArkitect fragment → registry) fixed by Fable

### Phase 5 — Post-fix re-audit (V5 loop)

- ✅ T5.1 Fresh-eyes re-audit → audits/post-fix-reaudit-2.md (opus). Verdict:
  SHIP-WITH-NITS — 0 CRITICAL / 1 MAJOR / 1 MINOR / 2 INFO; residual-rot sweep
  CLEAN (all claimed-fixed M-IDs re-verified against HEAD); no cross-wave
  regressions.
- ✅ T5.2 Findings resolved by Fable (commit 2173b0a; the commit message says
  "R-04" for the banner nit — the report's correct ID is R-03):
  - R-01 (MAJOR): M-053 parse failed on Composer 2.x SPLIT-literal proxies
    (real format, e.g. this repo's bin/phpunit) — pre-existing latent bug, not
    a wave regression, but it was claimed fixed. Parse now also tests per-line
    joined literals; mock-proxy verified end-to-end (split resolves,
    single-literal resolves, broken fails loudly).
  - R-02 (MINOR): install_signed marker check anchored to a header line on a
    regular file; symlinks (incl. dangling = hook managers) treated as foreign.
    Eight edge cases exercised.
  - R-03 (INFO): duplicate single-tool banner removed (options.inc.bash).
  - R-04 (INFO): M-077 (src/ PHPStan rules + composer plugins unit-untested)
    stays open as deferred follow-up work alongside M-071/M-072 — pre-existing
    coverage gap, not a defect.

### Phase 6 — Deferred-work wave (authorised 2026-07-15: "get this repo really as tight and clean as possible")

The Phase-5 deferrals were sequencing-safety deferrals, not optional work. User
instructed they now be completed. Four parallel agents, disjoint file scopes,
none commit (coordinator stages explicit paths per track after grading —
lesson from the W4 collision applied).

- ✅ T6.1 (m072-deploy, opus): WP-S7 + M-072 landed (2ba9a81), grade A−.
  Harness (8 scenarios) proven green against the monolith first; 726→152-line
  orchestrator + 7 scripts/lib/ modules; behaviour identity verified
  (stdout/tree/perms). Fable follow-ups before commit: fixer gates (rector×3 +
  fixer + phpstan — 16 errors incl. TWO runtime-breaking Safe conversions:
  Safe\mkdir/chmod return void, so `mkdir||fail` and assertTrue(chmod)
  became always-fail; rewritten as bare statements, harness re-proven green);
  DeployProcessRunner autoloading moved from require_once to an autoload-dev
  entry (fixture-namespace precedent). NOTE: a concurrent unassigned
  "bash-scripts" agent (not spawned by the coordinator) also attempted M-072 —
  collision self-resolved (its modules deleted by itself); duplicate-assignment
  lesson from W4 re-confirmed.
- ✅ T6.2 (m071-stubs, sonnet): M-071 landed (49b93f9), grade A−. Byte-identical
  output independently re-verified (diff vs git-HEAD originals, consumer-layout
  simulation, both bootstrap failure-message variants). Fable follow-up: the
  agent's new test file was not fixer-gate clean (rector×3 + fixer pending,
  5 tautological assertNotFalse) — applied and fixed before commit.
- ✅ T6.3 (m077-tests, opus): M-077 landed (c959d72), grade A. 126 new tests /
  173 assertions: 19 rule tests (violation shapes + identifier + negatives,
  detector-branch coverage; direct-AST house style) + 4 plugin wiring tests
  against a guarded runtime-only Composer\* stub surface (composer/composer is
  not installed; stubs are class_exists-guarded, non-autoloaded, assets-only).
  Zero src/ bugs found. Fable follow-ups before/after commit: fixer-gate
  fixpoint applied; agent's report arrived late (after my independent
  verification and commit) and corroborated everything. Post-commit unlock
  (7e88223): tests/Small/ComposerPlugin/* added to PHPStan excludePaths (same
  absent-Composer-API rationale as src/ComposerPlugin/*), enabling the agent's
  preserved BEHAVIORAL PhpStanGuardPlugin test (warning paths + silence) in
  place of the wiring-only version. Follow-up open: behavioral tests for the
  other 3 plugins (stubs already ship CapturingIO/FakeRootPackage).
- ✅ T6.4 (shellcheck-sweep, sonnet): landed (18a5199), grade A. 158 findings:
  64 real fixes (all SC2155 exit-code masks; SC2318 real latent bug in
  qaToolGateAllows; SC2145/2206/2207/2010; archiveToolLog ls|grep → find+
  mapfile), 2 dead vars deleted, rest justified targeted directives. CI gate
  raised to -S warning AND widened by Fable to qaConfig/ + bin stubs + git
  pre-commit hook source. Dead travis files deleted (repo's own abandoned
  Travis setup, zero references).
- ✅ T6.5 Fable grading + integration + commits/pushes: all four tracks landed
  and CI-green on origin (18a5199, 2ba9a81, aa1b9d2, c959d72 — run 29424679965
  success). Full local read-only pipeline run twice; the only failures were
  (a) container-only composerChecks artifact (F-ENV-1), and (b) the broken
  Infection lane — repaired in 7e88223 (see findings above), giving the
  branch's FIRST complete mutation run: 1325 mutants, 100% mutation coverage,
  Covered Code MSI 75.92 vs the 77 floor.
- ✅ T6.6 (mutant-killer, opus): grade A. Covered Code MSI 75.92% → 84.68%
  (Fable-verified with an independent infection run: 1325 mutants, 1110
  killed, 203 escaped, exit 0), escapes concentrated in message-builder
  concats killed via exact-output contract tests (scanner report formats,
  package-type guidance, psr4 early-return) + a multi-occurrence aggregation
  fixture. No src changes; no bugs found; harmless/equivalent mutants
  documented and skipped. Floors RAISED 71/77 → 82/82 (ratchet locked 2.7pp
  under measurement for timeout variance). Residual escapes: LinksChecker
  (29) is the top remaining file — future wave candidate.

Findings surfaced by the T6.5 full-pipeline verification (2026-07-15):
- FIXED — qaConfig/infection.json (repo's own) resolved its source dir
  config-relative to qaConfig/src (nonexistent) and its logs likewise; never
  caught because CI has no Xdebug so infection is always skipped there.
  Paths corrected to ../src and ../var/qa/... (tmpDir was already correct).
- FIXED — 6 risky tests (fail-on-risky fires only in coverage runs, so
  local-only): FactorySealedRuleTest and ExplicitPackageTypeCheckTest
  executed collaborator classes without declaring them; added #[UsesClass].
  Also silenced 12 PHPUnit notices by giving ForbidMagicStringAssertionRuleTest
  the class-level #[AllowMockObjectsWithoutExpectations] its newer siblings use.
- F-ST-2 (open): the phpStrictTypes gate greps for the literal string
  'strict_types' ANYWHERE in the file — a comment mentioning it passes the
  gate (observed: m077's MissingStrictTypes.php fixture passes despite having
  no declaration). It also ignores pathsToIgnore (scans fixture dirs). A
  stricter gate (anchored declare-regex + ignore support) is a behaviour
  change for consumers — needs its own slot.
- F-ENV-1 (open, environment robustness): composerChecks runs
  `phpNoXdebug -f "$(which composer)"`, which breaks when composer is a shell
  wrapper (as in this dev container — the wrapper text is executed as PHP and
  echoed). CI's composer is a real PHP entrypoint, so CI is unaffected; local
  aggregate runs in such containers report a false composerChecks failure.

Open findings from Phase 6 (not yet actioned):
- F-IFS-1 (flagged by shellcheck-sweep, deliberately not changed mid-sweep):
  bin/qa captures standardIFS BEFORE setting IFS=$'\n\t', but
  includes/options.inc.bash (sourced after) re-captures standardIFS from the
  already-modified IFS — so infection.inc.bash's `IFS=$standardIFS` "restore"
  is a no-op (restores \n\t, not the shell default). Restoring the intended
  behaviour would change Infection's word-splitting — needs its own focused
  slot with testing, not a drive-by fix.
- Production CI incidents 2026-07-15 (all resolved; lessons in
  CLAUDE/prepush-verification.md): 794dbc1 failed on unfixed W5 test files
  (fixer gates); 87b993a failed on Rector 2.5.7 floating in via untracked
  lock (design contradiction: tool-install.bash documented a tracked lock,
  .gitignore excluded it). Fixed by 87b993a + 1118447 (lock now tracked).

GATED ON USER (unchanged): pushing the local commits on php8.4 to origin.

## Ground rules for all audit agents

1. Every finding needs **evidence**: file:line references, quoted claim vs
   actual code behaviour. No unverified assertions — mark speculation clearly.
2. Severity scale: CRITICAL (actively wrong/dangerous) / MAJOR (misleading,
   drift) / MINOR (stale, cosmetic) / INFO.
3. Docs auditors: verify claims **against the code**, not against other docs.
4. Bash auditors: run `shellcheck` where useful; distinguish correctness bugs
   from style; note bash-isms that argue for/against staying in bash.
5. Audit-only: agents may write ONLY inside this plan folder.

## Decisions log

- 2026-07-15: Plan created. Audit-only mode confirmed. Bash-vs-rewrite decision
  deferred to T2.2 (user gut: keep bash).
- 2026-07-15 (later): **USER RULING** — no warn→enforce staging ("huge over
  complexity"); breaking consumer CI on valid failures is the repo's purpose;
  implementation authorised ("do the job, do it properly, no fucking about").
  Coordinator resolved Q-2 (retire annotations gate), Q-3 (delete dead
  lock/timing hooks), Q-4 (skills php-qa-ci-owned), Q-6 (code default 1 is
  truth, fix docs). Q-1/Q-5 moot. See fix-plans/risk-and-verification-2.md.
- 2026-07-15: USER RULING — php8.4 branch requires PHP ^8.4 (the ^8.3 floor was
  cutover-only). composer.json updated, lock resynced (commit 76a9265). Docs
  agent instructed to state 8.4.
- 2026-07-15: W1 committed (5f3f409). W2 (bash-core hygiene), W3 (docs rot),
  W4 (consumer scripts) dispatched as parallel opus agents with disjoint file
  ownership and explicit-path staging.
- 2026-07-15 (afternoon): W2 (689aa22), W3 (e9ef777), W4 (774e218 + Fable
  follow-up f812b82) all landed and Fable-graded A/A/A. Ownership-model
  exceptions ruled: git pre-commit hook = SHARED/signature (marker-gated,
  foreign hooks preserved + warned); GH Actions workflow = SEED-ONCE. Lesson
  recorded: coordinator and w4 agent implemented the same ruling concurrently
  (messages crossed) — collision reconciled by coordinator; future rulings
  must assign a single implementer explicitly. Integration battery green:
  Small 149/149, shellcheck -S error clean, mdlinks exit 0.

## Notes & Updates

- 2026-07-15: Phase 1 agents dispatched. Recovery cron d3e8b5bb active (hourly :43).
- 2026-07-15: Fable spot-checks written to synthesis/fable-spot-checks-1.md.
  **Headline: FS-008 CRITICAL — PSR-4 validation has been a silent no-op since
  commit e0240dc (2023-12-18) accidentally emptied psr4Validate.inc.bash; every
  consumer project has had a false-green psr4 gate for ~19 months.** Also
  FS-001 (dead functions + docs of dead config var), FS-002 (dead exit-code
  capture in bin/qa single-tool path), FS-009 (triple tool-registry SSoT).
- Closed 2026-09-10: F-IFS-1 targeted includes/options.inc.bash and infection.inc.bash, both gone with the Bash pipeline (Plan 00003); M-071/M-072 landed in Phase 6; M-077's gap is covered by tests/Small/PHPStan/Rules and tests/Small/ComposerPlugin; the php8.4 push gate is long past (php8.5 is the default branch).
