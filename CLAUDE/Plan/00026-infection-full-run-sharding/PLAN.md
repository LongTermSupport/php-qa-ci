# Plan 00026: infection full run sharding

**Status**: Not Started
**Created**: 2026-10-27
**Owner**: joseph
**Priority**: High

## Overview

A pull request that changes `qaConfig/infection.json` or `qaConfig/phpunit.xml` turns the Infection lane into a full
run of the whole source tree, and that run does not fit the required check's job limit (issue #344). The measurements in
[MEASUREMENT.md](./MEASUREMENT.md) show where the time goes: coverage generation, then a long serial mutation run, with
mutants per file very skewed (a median file has few dozen, the largest has about two thousand).

This plan splits the full run across several CI machines. Coverage is generated once and handed to every shard as an
artifact, the mutants are divided by count rather than by file, and one aggregating step judges the combined result
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

- [ ] ⬜ **Task 1.1**: Design the split from the measurements: how shards get their mutant share (a cheap per-file
  weight, since a whole-tree dry run costs far more than a shard), how coverage travels between jobs, how the verdict is
  aggregated from the shards' JSON logs, and how a lane run locally or in a single job still behaves as today.
- [ ] ⬜ **Task 1.2**: Review the design for the vacuous-kill check, the preloaded-source check and the diff-floor rules,
  which must hold per shard and in the aggregate.

### Phase 2: Build

- [ ] ⬜ **Task 2.1**: Failing tests first for the shard selection and the aggregation, then the implementation under
  `src/`, with a thin `bin/` entry point.
- [ ] ⬜ **Task 2.2**: Workflow and template change that runs the shards in a matrix and aggregates.
- [ ] ⬜ **Task 2.3**: Documentation for the Infection lane and the CHANGELOG entry.

### Phase 3: Prove

- [ ] ⬜ **Task 3.1**: Run it on a pull request that touches `qaConfig/infection.json` and show that the required check
  finishes and that the aggregate matches a serial full run on the same tree.

## Success Criteria

- [ ] A pull request changing `qaConfig/infection.json` finishes its required check.
- [ ] The aggregate verdict matches a serial full run on the same commit, floors unchanged.
- [ ] The consumer template and docs describe the mechanism.

## Delivery & Milestones

- Measurements recorded in [MEASUREMENT.md](./MEASUREMENT.md).
