# Plan 00017: fix every deferred defect and retire the record

**Status**: In Progress
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High

## Overview

The Owner's ruling: this repository does not do baselines, it fixes things. Plan 00016 built
`qaConfig/defect-record.neon`, a list of defects found and not fixed, and the agent filled it
with seven entries labelled "Owner decision" that were in fact fixable, or belonged upstream as
bug reports. The same shape survives in one PHPStan `ignoreErrors` entry and in the three
`known-gaps` entries `composer.json` declares against the Defence Before Fix toolchain
specification.

This plan fixes every one of them, then removes the defect-record mechanism (the Owner chose
this over keeping it empty). A defect is fixed when found; one that cannot be fixed here, because
its code is upstream, is a filed upstream issue. Method 1.1.0's deferral clause asks only that a
deferral be recorded somewhere enumerable, and an issue tracker is that.

It also carries two Owner rulings from the same session: the hooks daemon is upgraded to v3.68.0;
and `php8.4` takes a bug fix only when a `php8.4` project reports the bug (`php8.3` is dead), so
an issue must state the release line it was found on. Of the open `php8.4` bugs, only #36 is
backported proactively; the rest stay known `php8.4` issues until someone reports them.

## Goals

- Every deferred defect fixed, or filed upstream where the code is not ours
- `qaConfig/defect-record.neon` and everything that reads, checks or lists it removed
- No `ignoreErrors` entry in `qaConfig/phpstan.neon`, and no `known-gaps` in `composer.json`
- The hooks daemon at v3.68.0 with every post-upgrade task done
- Issues state their release line, and #36 fixed on `php8.4`

## Non-Goals

- Backports to `php8.3`, which is dead
- Upgrading `php8.4`'s bundled tools (issue #18), #37 and the 100% diff-MSI default on `php8.4`:
  fixed there only if a `php8.4` project reports them

## Tasks

### Phase 1: hooks daemon v3.68.0

- [x] ✅ **Task 1.1**: Upgrade, remove the stale `daemon_restart_verifier` key, carry out the six post-upgrade tasks, reconcile the 24 truth changes, review the newly available handlers
- [x] ✅ **Task 1.2**: Restore the generated `CLAUDE.md` region and commit on a branch through the battery

### Phase 2: fix the deferred defects (Defence Before Fix: detector red first)

- [x] ✅ **Task 2.1**: The generated `CLAUDE.md` region is written in the markdown formatter's canonical form, so a formatter pass changes nothing (blank lines inside the markers; the attribute in a code span)
- [x] ✅ **Task 2.2**: The `phpArkitect` lane honours `withIgnoredPaths()`, anchored to the project root
- [x] ✅ **Task 2.3**: The `infection` lane honours `withIgnoredPaths()`
- [x] ✅ **Task 2.4**: A `withIgnoredPaths()` entry that matches nothing fails, as a stale `withUnanalysedPath()` does
- [x] ✅ **Task 2.5**: The three hooks-daemon defects: confirm which v3.68.0 fixes; file each one it does not upstream through `hooks-daemon issue-report`

### Phase 3: retire the record

- [x] ✅ **Task 3.1**: Remove `defect-record.neon`, `src/DefectRecord/`, its lane check, the `bin/rules` listing and the agent-summary section; `Changed — breaking` changelog entry
- [x] ✅ **Task 3.2**: Rewrite `CLAUDE/DefenceBeforeFix.md` and the defaults page: a defect is fixed now; upstream code gets an upstream issue
- [x] ✅ **Task 3.3**: Remove the `ignoreErrors` entry by fixing `RequireExplicitDIAttributeRule`

### Phase 4: the declared known gaps

- [ ] 🔄 **Task 4.1**: Identify each PHPArkitect tier rule, resolvable by `bin/rule-doc`, with a single-file run (red committed, fix pending)
- [x] ✅ **Task 4.2**: Carry PHPStan's native identifier catalogue offline for `bin/rule-doc`
- [ ] ⬜ **Task 4.3**: Remove the `known-gaps` entries and the decisions they cite

### Phase 4b: defects found while working (fixed, red first)

- [x] ✅ **Task 4.4**: `withTypeCoverageFloors(declare:)` set a floor type-coverage 2.4 ignores; the argument is removed
- [x] ✅ **Task 4.5**: `ShellCheckInstaller` never removed its staging directory; `TemporaryDirectory` does, for both installers

### Phase 5: release lines

- [ ] ⬜ **Task 5.1**: `CLAUDE.md` and `README.md` state the ruling: `php8.5` is current, `php8.4` takes a fix when a `php8.4` project reports a bug, `php8.3` is dead
- [ ] ⬜ **Task 5.2**: An issue form whose release line is required, held to the live branches by a test
- [ ] ⬜ **Task 5.3**: Backport #36 (FlipAssertRector inverts assertions) to `php8.4` and release it (on hold for the Owner)

## Success Criteria

- [ ] `qaConfig/defect-record.neon` does not exist and nothing refers to it
- [ ] `qaConfig/phpstan.neon` has no `ignoreErrors`, and `composer.json` declares no `known-gaps`
- [ ] Both battery runs pass on `php8.5`, and on `php8.4` for its fixes
- [ ] Issues cannot be opened without a release line

## Delivery & Milestones

- <!-- delivery commit hashes -->
