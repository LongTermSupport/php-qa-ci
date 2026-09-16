# Plan 00013: qa pipeline defect sweep

**Status**: 🔄 In Progress
**Created**: 2026-09-16
**Owner**: Joseph Edmonds
**Priority**: Medium

## Overview

A day of heavy multi-agent use of php-qa-ci from a consuming project
(`ballicom/accounts-api`) produced a list of candidate defects in the harness
itself. The Owner's direction is that php-qa-ci, as a first-party tool, must not
actively hurt the projects that use it: context bloat, misleading exit codes and
permanently-red lanes all cost agent time on every single run.

This plan records the candidate catalogue with a verification verdict for each
one, then fixes the confirmed defects, one commit each, each with a test that
fails without the fix. Several candidates turned out not to be defects, and
recording why is part of the deliverable — an unverified seed acted on is how a
correct behaviour gets "fixed" into a wrong one.

Scope boundary: the `--agent-mode` terse-stdout work is a separate branch and
owns the PHPStan lane's stdout shaping. This plan does not touch it.

## Goals

- Every candidate carries a written verdict: confirmed, or not-a-defect with the
  evidence that settles it.
- Every confirmed defect is fixed with a test that fails without the fix.
- Lock contention is distinguishable from a QA failure by exit code alone.
- No lane is permanently red for a reason the consuming project cannot fix.
- Full unfiltered `CI=true bin/qa` exits 0 on this branch.

## Non-Goals

- The `--agent-mode` terse output feature, or any reshaping of the PHPStan
  lane's stdout.
- Raising or lowering any project's Infection floor.
- Changing what any PHPStan rule detects.

## Defect Catalogue

Verdicts come from reading `src/`, running the pipeline in this worktree, and
running consumer-side `bin/qa` probes from the `accounts-api` checkout.

### Confirmed

| Ref | Defect                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Evidence                                                                                                             |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| D1  | Lock contention returns exit 1, identical to a real QA failure. A consuming hook cannot tell "another run holds the lock" from "your code is broken", and the banner goes to stdout only.                                                                                                                                                                                                                                                                                                                                                                                      | `src/Pipeline/Runner/Pipeline.php` (the `acquire()` false branch); `src/Pipeline/Lock/RunLock.php` contention banner |
| D2  | Retention cannot bound the log directory. `LogArchiver` keeps ten logs per pattern, and a `-p` run derives its pattern from the full absolute path, so every distinct file analysed mints a new unbounded pattern. Past the warn threshold the lane prints HIGH LOG FILE COUNT and "consider clearing" on every run, pruning nothing.                                                                                                                                                                                                                                          | `src/Pipeline/Process/LogArchiver.php`; observed archive names carry the whole path                                  |
| D3  | Latent, not active. The block writer does not guarantee a blank line before the closing tag, so a template whose tag sits under a bullet yields a tag a markdown formatter indents; the block then stops matching the template and is rewritten, and auto-committed, on every deploy. The shipped template happens to carry that blank line, so php-qa-ci's own block is safe today by a property of one template rather than of the writer, and a future template edit would reintroduce the churn silently. The churn originally reported came from another package's block. | proven red against the previous writer: with a bullet above it, the closing tag lands with no blank line             |
| D4  | `bash bin/qa` fails with a bare shell syntax error. `bin/qa` is a PHP file behind a `php` shebang, so an interpreter-prefixed invocation produces an unexplained parse error.                                                                                                                                                                                                                                                                                                                                                                                                  | `bin/qa` opening lines                                                                                               |
| D5  | Per-file PHPStan on a phar-tool config file is permanently red. `qaConfig/phparkitect.php` and `qaConfig/composer-dependency-analyser.php` name classes that live only inside `vendor-phar/*.phar`, which the project's PHPStan run has no autoloader for.                                                                                                                                                                                                                                                                                                                     | consumer `bin/qa -t stan -p qaConfig/phparkitect.php` exits 1 with 108 `class.notFound` / `method.nonObject` errors  |
| D6  | `bin/rule-doc` cannot resolve a consuming project's own rule identifiers. The row pattern hardcodes the `phpqaci.` prefix and the index path is fixed to this package's own rule index, so a project identifier gets "Unknown rule identifier".                                                                                                                                                                                                                                                                                                                                | `src/PHPStan/RuleDocResolver.php` row pattern and index constants                                                    |

### Not a defect — verified, no change

| Ref | Candidate                                                                                         | Verdict                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| --- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| N1  | A twenty-line "Instructions for interpreting errors" preamble prints on every run.                | It prints only when PHPStan reports errors, and it is PHPStan's own agent guidance rather than php-qa-ci output. A green per-file run in this worktree produced 47 lines total with no preamble. Terse output belongs to `feature/agent-mode`.                                                                                                                                                                                                                                                                                                                                       |
| N2  | Rector loosens a test assertion by renaming `expectExceptionMessage` to the IsOrContains form.    | The rename is behaviour-preserving. The original has always been a substring-containment check, never an exact match; PHPUnit 13.2 renamed it to say so, and Rector's composer-based set applies the rename only at that version floor. Nothing is loosened. A test needing exact matching wants the regex-matching expectation with an anchored pattern.                                                                                                                                                                                                                            |
| N3  | The composer plugin's managed CLAUDE.md block rewrite is not byte-stable.                         | The writer is idempotent. Replayed twice against the consuming project's real `CLAUDE.md` it reported no changes both times and produced an empty diff. The churn the seed observed came from an adjacent package's block. The real hazard it exposes is D3, which this plan fixes.                                                                                                                                                                                                                                                                                                  |
| N4  | Infection's "consider increasing the required Covered Code MSI" contradicts the shipped advisory. | The two agree. The shipped advisory argues against a **100%** floor specifically, because provably equivalent mutants make 100 unreachable honestly. It does not argue against ratcheting, and this package's own `qaConfig/qa.php` ratchets: a raise-only floor sitting a few points under the last measured covered MSI, which is precisely what Infection's line suggests doing. Suppressing it would mean hiding correct advice the project already follows, and the line is Infection's own streamed output, so filtering it would cost the live progress a mutation run needs. |

### Carried, not started here

| Ref | Candidate                                                                                              | Position                                                                                                                                                                                                                   |
| --- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| X1  | PHPArkitect has no fixture harness, so a project cannot prove a zero-instance architecture rule fires. | A genuine Detector gap and a real piece of work: it needs a fixture class set, a single-rule runner analogous to the PHPStan one, and a lane to run it. Sized beyond this sweep, and carried whole rather than half-built. |

## Tasks

### Phase 1: Confirmed defects, one commit each

- [x] ✅ **Task 1.1**: D1 — a distinct, documented exit code for lock contention, plus a one-line stderr message.
- [x] ✅ **Task 1.2**: D2 — make retention bound the directory, not merely each pattern.
- [x] ✅ **Task 1.3**: D3 — guarantee a blank line before the closing block tag so no formatter reflows it.
- [x] ✅ **Task 1.4**: D4 — `bin/qa` answers every invocation form, as a shell shim over `bin/qa.php`.
- [x] ✅ **Task 1.5**: D5 — let PHPStan discover the phar tools' config APIs, so the per-file run on those configs is actionable.
- [x] ✅ **Task 1.6**: D6 — `bin/rule-doc` resolves a consuming project's declared rule indexes.
- [x] ✅ **Task 1.7**: struck. The Infection candidate is N4: verified as agreement, not contradiction.
- [x] ✅ **Task 1.8**: `ForbidRawSqlRule` narrowed positively to `Arg`, a pre-existing PHPStan failure on the base branch.

### Phase 2: Verification

- [ ] ⬜ **Task 2.1**: Full unfiltered `CI=true bin/qa` exits 0 in this worktree.
- [ ] ⬜ **Task 2.2**: Each pull request's QA workflow is green on its head commit.

## Success Criteria

- [ ] Every catalogue row carries a verdict, and every Confirmed row is fixed or explicitly carried.
- [ ] Each fix ships with a test that fails without it.
- [ ] Lock contention exits with its own code, and that code is documented in the package docs.
- [ ] Per-file PHPStan on a phar-tool config file is green in a consuming project.
- [ ] Full unfiltered `CI=true bin/qa` exits 0.
- [ ] Release-bound consequences are written into `CLAUDE/UPGRADES/UNRELEASED/`, or the plan states it has none.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00013-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Catalogue recorded with a verdict against every candidate.
