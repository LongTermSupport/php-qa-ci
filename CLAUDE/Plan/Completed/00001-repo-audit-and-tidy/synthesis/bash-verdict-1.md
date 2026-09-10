# Verdict: Should php-qa-ci stay in bash? — v1

Author: Fable (coordinating model), 2026-07-15.
Inputs: audits/bash-quality-1.md §"Bash fitness", audits/architecture-1.md
§"Bash substrate", audits/bash-scripts-1.md, direct Fable reads of bin/qa,
functions.inc.bash, options.inc.bash, setConfig.inc.bash, and empirical
verification of the silent-pass gate class.

## VERDICT: KEEP BASH — with a mandatory quality gate, not as-is

The user's gut is right, and the decisive argument is one neither audit put
front-and-centre:

**The extension API is bash.** Consumer projects override behaviour via
`qaConfig/qaConfig.inc.bash`, `qaConfig/tools/{tool}.inc.bash`, `hookPre.bash`,
`hookPost.bash` — all sourced bash fragments that read and mutate the
pipeline's shell variables (`pathsToCheck`, `useInfection`,
`arkitectExcludePaths`, …). A rewrite in any other language either breaks
every consumer override in every consuming project (unacceptable — the brief
is "without breaking anything") or requires embedding a bash evaluation layer
anyway (worst of both worlds). The orchestrator's language is a public,
consumed contract, not an implementation detail.

Supporting evidence:
1. **The job is what bash is for.** The pipeline's real work is ordered
   invocation of CLI binaries with env control, exit-code plumbing and output
   capture. Both audits agree the runtime layer is cleanly separable and
   launcher-shaped (architecture-1.md substrate section).
2. **The bug evidence indicts practices, not the language.** The CRITICALs
   (psr4 empty-file no-op, strict-types find-precedence, commented-out
   annotations gate) are silent-process failures that a test gate would have
   caught in any language. None is a "bash was too hard" bug; all are "nothing
   ever verified this gate fires" bugs.
3. **Capability is proven in-repo.** The newer modules (infection,
   branchNamePolicy, lock, timing, the read-only/aggregate machinery) are
   well-designed, contract-documented bash — graded best-in-class by the opus
   audit. The quality gradient is temporal, not linguistic.
4. **Semantic logic already migrates to PHP naturally.** ~36 PHPStan rules,
   Psr4Validator, LinksChecker, package-type checks live in src/. The healthy
   pattern — bash orchestrates, PHP decides — already exists; formalise it.
5. **A rewrite is the highest-risk move available** against a multi-project
   consumer contract with effectively zero orchestration tests to pin current
   behaviour during migration. You cannot safely rewrite what you cannot test;
   by the time tests exist, the main argument for rewriting has evaporated.

## Mandatory conditions (the quality bar that makes "keep bash" defensible)

C1. **Shellcheck gate in this repo's own CI** — bash-quality found real
    SC-class bugs; the repo that ships a QA pipeline must QA its own bash.
C2. **One shared tool-driver + declarative per-tool metadata** (architecture
    move #1): retry, CI/interactivity, read-only, log archival, path handling,
    JSON, timing become driver concerns; tool fragments become command
    builders. Kills the 11×-duplicated retry loop, the triple-maintained tool
    registry, and the 5 divergent errexit idioms. Public names/aliases/
    override paths unchanged.
C3. **Gate-liveness invariant**: the driver fails loudly if a tool fragment
    is empty, fully commented out, or completes without invoking anything —
    the entire FS-008/FS-010/BQ-007 bug class becomes structurally impossible.
C4. **Orchestration test harness** (bats or the existing PHPUnit-shells-out
    pattern proven by InfectionDiffModeTest): boot sequence, config cascade
    ordering, driver behaviours (retry/read-only/aggregate), one smoke test
    per tool fragment asserting the underlying binary is actually invoked.
C5. **Hygiene sweep with the driver refactor**: quoting/array expansion (stop
    relying on the global IFS=$'\n\t' accident), `${var:?}` guards on rm -rf
    paths, no `eval`, no `sed -i` on consumer files without read-only guards.
C6. **Keep pushing semantics to PHP**: anything needing parsing/data
    structures (e.g. future JSON output modes, timing schema) goes in src/,
    invoked by thin bash.

## What would change this verdict
- If a future requirement demands Windows-native support (no bash), or
- consumer overrides are deprecated as an API (multi-year migration), or
- the driver refactor (C2) proves impossible to land incrementally.
None is currently indicated.

## Rejected alternatives (considered)
- **Full PHP rewrite**: breaks the qaConfig override contract; highest risk;
  no evidence of need. Rejected.
- **Hybrid orchestrator (PHP core + bash shims)**: preserves surface but
  doubles the contract area and still requires the test harness first.
  Strictly dominated by C2+C4 on the risk/value frontier. Rejected for now;
  re-evaluate only after C2/C4 exist (they are prerequisites for ANY
  substrate change, which is itself an argument for doing them and stopping).
