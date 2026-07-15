# Plan 00001 — Repo Audit & Tidy (php-qa-ci)

**Status**: In Progress — Phase 4 (Implementation)
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
- ⬜ T4.5 W5 Structural: registry SSoT (M-011) + shared driver (M-010), characterisation tests first

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
