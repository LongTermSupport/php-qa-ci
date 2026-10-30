# Plan 00026: infection full run sharding

**Status**: Not Started
**Created**: 2026-10-27
**Owner**: joseph
**Priority**: High

## Overview

A pull request that changes the Infection or PHPUnit configuration (`qaConfig/infection.json`, `qaConfig/phpunit.xml`,
their alternate names, or the test bootstrap, as `InfectionFullRunTriggers` lists them) turns the Infection lane into a
full run of the whole source tree, and that run does not fit the 45-minute job limit of the required check (issue #344). The measurements in
[MEASUREMENT.md](./MEASUREMENT.md) show where the time goes: coverage generation, then a long serial mutation run, with
mutants per file very skewed (a median file has few dozen, the largest has about two thousand).

This plan splits the full run across several CI machines. Coverage is generated once and handed to every shard as an
artifact, the source files are divided across shards by a cheap per-file weight (executed lines from the coverage index, one file
never split), and one aggregating step judges the combined result
against the unchanged floors. Nothing is lowered: the mutant timeout stays as it is, `--only-covering-test-cases` is not
used, and a `core` test suite is not introduced. The line-level diff question (changed lines only for pull requests) is a
separate Owner decision and this plan does not depend on it.

## Goals

- A pull request that changes the Infection or PHPUnit configuration finishes its required check within the job limit.
- The combined verdict equals what one serial full run would give: same floors, same escaped-mutant list, nothing lost
  between shards.
- The shipped workflow template carries the same mechanism, so a consumer whose full run is too long can adopt it.

## Non-Goals

- Lowering the mutant timeout, `--only-covering-test-cases`, or a `core` test suite.
- Changed-lines-only diff mode for pull requests (an Owner decision, tracked separately).
- Changing the diff-mode scope rules or the full-run triggers.

## Tasks

### Phase 1: Design

- [ ] ⬜ **Task 1.1**: Design the split from the measurements: how shards get their file share (a cheap per-file
  weight, since a whole-tree dry run costs far more than a shard), how coverage travels between jobs, how the verdict is
  aggregated from the shards' JSON logs, and how a lane run locally or in a single job still behaves as today. See
  [DESIGN.md](./DESIGN.md).
- [ ] ⬜ **Task 1.2**: Review the design for the vacuous-kill check, the preloaded-source check and the diff-floor rules,
  which must hold per shard and in the aggregate; independent reviews in
  [subagent-reports/261030-design-review-fresh-reviewer.md](./subagent-reports/261030-design-review-fresh-reviewer.md)
  and [subagent-reports/261030-design-review-second-fresh-reviewer.md](./subagent-reports/261030-design-review-second-fresh-reviewer.md).

### Phase 2.0: Prove assumptions before code

- [ ] ⬜ **Task 2.0a**: On a scratch branch with a long job limit, run the serial full lane once on a hosted runner;
  record total time, per-file mutant counts and the log. This is the Phase 3 baseline and fixes the shard count N.
  Hosted-runner sizing is unproven until this runs: also record whether the N it needs fits under the
  file-granularity ceiling of DESIGN section 1, how many in-scope covered files have no mutants in the log (DESIGN
  section 4, step 1b), and the cost of the `scope` job on the critical path (DESIGN section 5).
- [ ] ⬜ **Task 2.0b**: From that log and the coverage `index.xml`, compute the correlation of executed lines with
  mutants per file; decide the proxy against cached real counts.
- [ ] ⬜ **Task 2.0c**: Prove with a real Infection 0.35.8 run what the source reading in DESIGN section 0a leaves
  open: (1) positional absolute paths and `--filter` select the same files; (2) a comma list acts as alternatives and
  a path that is a prefix or suffix of another file's path selects only its own file when given absolute; (3) what a
  run that matches no file writes and exits with (source says exit 0, probably no log); (4) what a run whose matched
  files yield no mutants writes and exits with (a zero-count log or none); (5) the real set of top-level and `stats`
  log keys, kept as the merger's fixture; (6) how Infection compares a score to its floor (rounded or not); (7) that a
  downloaded coverage artifact works at the same checkout path in a second job; (8) whether Infection leaves any
  per-file trace of a processed file that yielded no mutants (text log, debug log, zero-count log), which decides the
  zero-mutant evidence rule of DESIGN section 4, step 1b; (9) that a path which is not the file's real path is
  silently dropped while other paths still match (the failure the join's per-file evidence exists for).

### Phase 2: Build (each step: failing tests and detectors first, Defence Before Fix)

- [ ] ⬜ **Task 2.1**: Detectors first. Write the red tests for the failure modes before any code: workflow-shape tests
  (one job named `QA Pipeline` with `if: ${{ !cancelled() }}`, explicit result checks and every other job in `needs`;
  `coverage-report` needs `pipeline`; `fail-fast: false`; `timeout-minutes`
  on every job; shard uploads `if: always()`, `overwrite: true`, no `tmp/`; handoff artifact names distinct from
  `coverage`; matrix never evaluated empty; a manual run is unsharded), join tests (missing shard, duplicate index,
  wrong count, differing hash, other-commit log, plan disagreement, file in two shards, mutant outside its shard's list,
  duplicate mutant, unknown log key, stats not reproducing `msi`, a planned file absent from its shard's evidence, a
  `running` or failed-exit `shard.json`), the configuration-floor strip test (`minMsi`, `minCoveredMsi`, `maxTimeouts`,
  `ignoreMsiWithNoMutations` removed from a shard's derived configuration, the original untouched), and the
  merger/judge property test (split a log into k, merge, same judgement). Decide whether the failures get an identifier page ("shard handoff broken").
- [ ] ⬜ **Task 2.1a**: Rewrite `tests/Small/PipelineTemplateRunsOncePerPushTest.php` (it pins the job keys to `qa` and
  `coverage-report` and binds the id `qa` to the pipeline body) for the new job set and the gate; keep
  `RequiredStatusChecksAreProducedTest` and `noScriptOfAWorkflowOrTemplateHoldsAnExpression` green.
- [ ] ⬜ **Task 2.2**: `InfectionShardDto`, parsing, builder and environment setting, settings row in
  `docs/configuration.md`; settings tests.
- [ ] ⬜ **Task 2.3**: `InfectionRunScope` extracted from `InfectionTool` with no behaviour change (existing tests stay
  green); planner tests.
- [ ] ⬜ **Task 2.4**: Weights (in-scope files only), balancer, `InfectionArguments::shard()`; balancer and argv tests.
- [ ] ⬜ **Task 2.5**: Lane stages `coverage` and `<i>/<n>`: the configuration strip, the `realpath()` check, the
  manifest, the coverage-stage refusal of N above the planned file count, `shard.json` (written after the lane empties
  its output directory, to a directory it does not empty; `running` then `finished` with exit status and per-file
  counts), empty-shard handling; lane tests. A new field on the `@api` `InfectionOptionsDto` is an optional trailing
  parameter.
- [ ] ⬜ **Task 2.6**: Log merger, floor judge (rounded score, strict less-than, `maxTimeouts`), plan recomputation,
  membership and per-file evidence checks, `join` stage.
- [ ] ⬜ **Task 2.7**: `bin/infection-plan` (PHP in `src/`, thin entry), registered in `composer.json` `bin` (with
  `.gitignore` and the executable bit; `ComposerBinManifestTest` pins them) and in the `qaConfig/qa.php` entry points;
  workflow and template changes, the scope step calling `$(composer config bin-dir)/infection-plan`, the gate as
  `!cancelled()` with explicit result checks, `coverage-report` needing `pipeline`.
- [ ] ⬜ **Task 2.8**: Large end-to-end test on a tiny fixture project; `docs/tools/infection.md` (listing the new join failure texts); `## Unreleased`
  entry (a new setting, a new entry point, a template change).

### Phase 3: Prove

- [ ] ⬜ **Task 3.1**: Run it on a pull request that touches `qaConfig/infection.json` and show that the required check
  finishes and that the aggregate matches the serial baseline of Task 2.0a under the tolerance rule of DESIGN section 4, step 5
  (mutant set, escaped list and verdict equal; timeout classing may move between killed and timeouted). Rerun one
  shard to show the re-join works with `overwrite: true`; fail one shard on purpose to show the gate goes red.
- [ ] ⬜ **Task 3.2** (optional): cached real-count weights if Task 2.0b says the proxy is poor.

## Success Criteria

- [ ] A pull request changing `qaConfig/infection.json` finishes its required check.
- [ ] The aggregate verdict matches a serial full run on the same commit, floors unchanged.
- [ ] The consumer template and docs describe the mechanism.

## Delivery & Milestones

- Measurements recorded in [MEASUREMENT.md](./MEASUREMENT.md).
