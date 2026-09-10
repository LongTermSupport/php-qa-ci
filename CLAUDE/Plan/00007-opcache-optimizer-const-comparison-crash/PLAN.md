# Plan 00007: OPcache optimizer const-comparison crash

**Status**: In Progress
**Created**: 2026-09-10
**Owner**: Joseph Edmonds
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Single-Threaded

## Overview

PHP 8.5.10's OPcache optimizer can emit a comparison opcode whose two operands are
both constants. The compiler is supposed to fold those, so the VM has no handler for
the pair; the fallback handler reads op1 as a variable slot, gets garbage, and either
segfaults inside `zval_undefined_cv` or compares garbage silently. The shape that
triggers it is ordinary code: an earlier `null === $x` check lets the DFA pass prove
`$x` is `null` on one branch, the pass substitutes the constant into a later
`[] !== $x`-style comparison on that branch, and then fails to fold it.

It surfaced as five identical CLI segfaults during full pipeline runs, every one an
Infection mutant of the same line in a consuming project. Infection is not the cause:
it rewrites source with PHP-Parser and runs the rewritten file through the normal
compiler, and hand-written idioms produce the same bad opcode. The pipelines stayed
green because Infection counts a non-zero exit as a killed mutant.

This plan records the evidence, decides where the defence belongs, and delivers the
parts that are php-qa-ci's to deliver. The full dossier is in
[investigation.md](investigation.md).

## Goals

- A consuming project cannot ship code that compiles to a const-const comparison on
  an affected PHP without the pipeline failing and naming the file and line.
- A pipeline run on an affected PHP with the DFA pass enabled says so, with the exact
  ini line that removes the bug class, before any tool runs.
- The bug, its proof and the reasoning are recorded once, here, in terms that hold
  for any consumer.

## Non-Goals

- Fixing PHP. The upstream report is deferred by the owner's ruling; Task 3.1 tracks it.
- Changing any host's php.ini. That is the host operator's job; this plan only states
  the recommended setting and detects its absence.
- Making Infection classify a segfaulting mutant as an error rather than a kill. That
  is upstream Infection behaviour and out of scope.

## Context & Background

- Crash site: `Zend/zend_execute.c:280` in `zval_undefined_cv`, reached from
  `ZEND_IS_NOT_IDENTICAL_EMPTY_ARRAY_SPEC_TMPVARCV_CONST_JMPZ_HANDLER`.
- The op_array in the core shows `IS_NOT_IDENTICAL` with `op1_type=CONST`,
  `op2_type=CONST`. The optimizer dump of the same file confirms
  `IS_NOT_IDENTICAL null array(...)`.
- Clearing bit `0x20` of `opcache.optimization_level` (the DFA pass, which holds
  SCCP) removes the bad opcode; the default mask reproduces it every time.
- A scan of every source file of three consuming projects through the optimizer
  found no const-const comparison in real code, so exposure today is mutants only.
- Detail, timeline, disassembly, core-dump fields and the idiom matrix:
  [investigation.md](investigation.md).

## Tasks

### Phase 1: Detector lane (the net)

- [ ] ⬜ **Task 1.1**: Lane `optimizerConstComparison` in the linting phase that
  compiles each checked PHP file with `opcache.enable_cli=1`, the default
  `opcache.optimization_level`, and `opcache.opt_debug_level=0x20000`, and fails on
  any comparison opcode with two constant operands, naming file and line.
  - [ ] ⬜ Failing test with a fixture reproducing the shape (from the dossier).
  - [ ] ⬜ Passing test with the original, unmutated shape.
  - [ ] ⬜ Runs the default optimizer mask explicitly so the verdict does not depend
    on the host's mask.
  - [ ] ⬜ Gated on `PHP_VERSION_ID` within the affected range from Task 2.2; skipped
    with a note outside it.
  - [ ] ⬜ Identifier `phpqaci.optimizerConstComparison`, docs page under
    `docs/tools/`, registry row, CLAUDE.md and README entries.
  - [ ] ⬜ Measure runtime on a large consumer; per-file `-p` support.
  - [ ] ⬜ Full pipeline green.

### Phase 2: Preflight advisory (the tripwire)

- [ ] ⬜ **Task 2.1**: Preflight probe next to the Xdebug probe: on an affected PHP,
  read `opcache.optimization_level` and `opcache.enable_cli`; if the DFA bit is set,
  print a warning naming the bug class and the ini line
  `opcache.optimization_level=0x7FFFBFDF`, for every SAPI on that host.
  - [ ] ⬜ Failing tests: affected version with DFA on warns; DFA off is silent;
    unaffected version is silent.
  - [ ] ⬜ Warning only, never a failure: the detector lane is the gate, and a host
    the consumer does not control must still be able to run QA.
- [ ] ⬜ **Task 2.2**: Affected-version table as one constant with a doc comment:
  lower bound the first version verified affected, upper bound the fix version once
  upstream publishes it, open-ended until then. Record what was actually tested.

### Phase 3: Upstream and follow-through

- [ ] ⬜ **Task 3.1**: File the upstream php-src report with the dependency-free
  reproduction from the dossier, once the owner lifts the deferral; record the
  issue number here and close the affected range when the fix version is known.
- [ ] ⬜ **Task 3.2**: Verify PHP 8.4's optimizer against the same fixture and record
  the result in the affected-version table.

## Dependencies

- Depends on: nothing.
- Related: Plan 00005 (the lane registry and `PipelineBuilder` this lane plugs into).

## Technical Decisions

### Decision 1: The defence is layered, and none of the layers is Infection-specific

**Context**: The crash was first seen under Infection, so the cheapest-looking fix is
to run mutants with the optimizer off. **Options Considered**:

1. Disable the DFA pass for Infection's mutant processes only, through an
   inheritable `PHP_INI_SCAN_DIR` entry. Cheap, but it masks the bug in the one place
   it is harmless (mutants are not shipped) while doing nothing for real code, which
   compiles with the same optimizer in every SAPI.
2. Host-level ini: clear the DFA bit for every SAPI on affected PHP builds. Removes
   the bug class everywhere it can execute, including production. Not something
   php-qa-ci can apply; it can only recommend and detect.
3. Detector lane compiling real code with the default mask and failing on the bad
   opcode. Catches an actual instance before it ships regardless of the host's mask,
   and is the only layer that protects a host nobody has reconfigured.
4. Preflight warning when an affected PHP still has the DFA pass on.

**Decision**: 2 is the recommendation to host operators, stated abstractly in the
docs; 3 and 4 are php-qa-ci's deliverables. 1 is rejected as a sole measure and not
built now: with 3 in place a segfaulting mutant costs one false kill, and the
`PHP_INI_SCAN_DIR` route is fragile through Infection's Xdebug-handler restart.
**Date**: 2026-09-10

### Decision 2: The detector runs the default optimizer mask explicitly

**Context**: A host that has already applied the recommended ini would never compile
the bad opcode, so a lane that inherits the host mask would go blind exactly where
the operator did the right thing. **Decision**: the lane passes
`-d opcache.optimization_level=0x7FFFBFFF -d opcache.enable_cli=1` itself, so it
reports what an unmitigated production host would compile. **Date**: 2026-09-10

### Decision 3: Warn, do not fail, on the host setting

**Context**: A consumer may run QA on a host it does not administer. Failing every
run there until an operator acts blocks unrelated work; the detector lane already
gates the thing that matters. **Decision**: preflight prints the warning and the ini
line and continues. **Date**: 2026-09-10

## Success Criteria

- [ ] The dossier's mutant shape fails the detector lane with file and line; the
  original shape passes.
- [ ] On PHP 8.5.10 with the default mask the preflight warning appears; with bit
  `0x20` cleared it does not.
- [ ] Both are documented under `docs/tools/` with stable identifiers resolvable by
  `vendor/bin/rule-doc`.
- [ ] Full unfiltered pipeline exit 0.

## Risks & Mitigations

| Risk                                                                              | Impact | Probability | Mitigation                                                                                                                          |
| --------------------------------------------------------------------------------- | ------ | ----------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| The detector's per-file compile is slow on large consumers                        | Med    | Med         | Measure in Task 1.1; the lane is in the linting phase where parallel-lint already spawns per-file processes, so reuse that batching |
| Upstream fixes it and the gate keeps running for nothing                          | Low    | High        | Version-gated lane and probe; closing the range is one constant                                                                     |
| The scan pattern misses a comparison opcode the optimizer can also leave unfolded | Med    | Low         | The fixture set is the dossier's idiom matrix; extend it as new shapes are found                                                    |
| A hand-written instance exists in a consumer today that the scan did not see      | High   | Low         | The scan covered three consumers' full `src/`; the lane makes it a standing check                                                   |

## Delivery & Milestones

- Investigation complete, dossier and plan recorded: (this commit)
