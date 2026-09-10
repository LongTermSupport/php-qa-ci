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

- [x] ✅ **Task 1.1**: Lane `opcache` in the linting phase that compiles each checked
  PHP file through OPcache with the default `opcache.optimization_level` and
  `opcache.opt_debug_level=0x20000`, and fails on any comparison opcode with two
  constant operands, naming the file, the function and its line range.
  - [x] ✅ Failing test with a fixture reproducing the shape (from the dossier).
  - [x] ✅ Passing test with the original, unmutated shape.
  - [x] ✅ Runs the default optimizer mask explicitly so the verdict does not depend
    on the host's mask.
  - [x] ✅ Gated on the affected range from Task 2.2; skipped with a note outside it,
    and on a host whose CLI has no OPcache.
  - [x] ✅ Identifier `phpqaci.opcache`, docs page under `docs/tools/`, registry row,
    CLAUDE.md, README, `docs/pipeline.md` and `docs/phpqa-tools.md` entries.
  - [x] ✅ Per-file `-p` support; 200 files per compile process.
  - [x] ✅ Full pipeline green.
- [x] ✅ **Task 1.2**: `opcache.file_update_protection=0` in the compile, and a crash
  rather than a pass when a file produced no dump. Dogfooding found both: OPcache never
  optimises, and so never dumps, a file written in the last couple of seconds, and the
  fixers rewrite files moments before this lane runs, so a just-fixed file was passing
  unchecked.

### Phase 2: Preflight advisory (the tripwire)

- [x] ✅ **Task 2.1**: Preflight probe next to the Xdebug probe: on an affected PHP,
  read `opcache.optimization_level` and whether OPcache is loaded; if the DFA bit is
  set, print a warning naming the defect class and the ini line
  computed from the host's own mask (`0x7FFEBFDF` on a default host), for every SAPI
  on that host.
  - [x] ✅ Failing tests: affected version with DFA on warns; DFA off is silent;
    OPcache absent is silent; unaffected version is silent.
  - [x] ✅ Warning only, never a failure: the lane is the gate, and a host the consumer
    does not control must still be able to run QA.
- [x] ✅ **Task 2.2**: Affected range as one constant pair with a doc comment in
  `OpcacheDefects`: lower bound the first version assumed affected, upper bound the fix
  version once upstream publishes it, open-ended until then. What was actually verified
  is recorded in the dossier.

### Phase 3: Upstream and follow-through

- [ ] 🔄 **Task 3.1**: The upstream php-src report. **Filed by the owner under a
  different agent/GitHub identity**, so the deliverable here is a report ready to post
  verbatim, not the posting itself. **Delegated in full to
  [Plan 00009](../00009-upstream-php-src-bug-report-opcache-const-comparison/PLAN.md)**,
  which owns filing and follow-through and reports back here; this plan keeps the
  evidence and [upstream-report.md](upstream-report.md), the single copy of the text.
  - [x] ✅ Draft it as [upstream-report.md](upstream-report.md): title, environment, the
    dependency-free reproduction, expected vs actual, the optimizer dump, the mask
    bisect, and what was ruled out. No reference to any private repository, host or
    consumer. Since rewritten under 00009 around a *crashing* six-line reproduction.
  - [ ] ⬜ Owner (other identity) files it at <https://github.com/php/php-src/issues>
    — 00009 Task 2.1.
  - [ ] ⬜ Record the issue number in `upstream-report.md` and in
    `OpcacheDefects`' doc comment, so the next reader of the affected-range constant
    can follow it upstream — 00009 Task 2.2.
  - [ ] ⬜ When a fix ships, set `FIRST_FIXED` in `OpcacheDefects` to that version,
    which retires both the lane and the advisory on newer PHP automatically
    — 00009 Task 3.3.
- [x] ✅ **Task 3.2**: Record 8.4 as unverified and do not measure it. Originally
  "verify PHP 8.4's optimizer against the same fixture"; closed as not worth doing, see
  Decision 5. `FIRST_AFFECTED` stays `8.5.0`, and `OpcacheDefects`, the dossier and the
  upstream report all say 8.4 is unverified rather than known-good, which is the accurate
  claim and the only one this plan needs.

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
`-d opcache.optimization_level=0x7FFEBFFF -d opcache.enable_cli=1` itself, so it
reports what an unmitigated production host would compile. **The mask is PHP's own
default, read from the binary, not one invented by setting every bit**: PHP ships
passes `0x4000` and `0x10000` off, and dogfooding caught this lane compiling with
`0x10000` on, i.e. reporting bytecode no production host produces. For the same
reason the advisory clears the DFA bit from the *host's* mask rather than from the
shipped default, so a host that turned other passes off is not told to restore them.
**Date**: 2026-09-10

### Decision 3: One `opcache` tool, not a tool per defect

**Context**: the first draft shipped the check as a tool named after the defect,
`optimizerConstComparison`. **Why that was wrong**: a tool is a permanent extension of
the public API — a `-t` token frozen by the characterisation test, a line in the CLI
help every consumer reads, a step in every full run, a stable identifier, and a docs
page. Paying all of that per *finding* would have meant a new tool for every future
OPcache codegen defect, each recompiling the whole codebase. **Decision**: one lane per
*kind of inspection*. `opcache` owns "what bytecode does this code compile to"; the
const-comparison defect is its first assertion and the next one is another assertion in
the same lane, over the same compile, under the same identifier. The general test for
when a new tool IS justified is now written down in
[CLAUDE/tool-boundaries.md](../../tool-boundaries.md), because the judgement is
reusable and was not recorded anywhere. **Date**: 2026-09-10

### Decision 4: Warn, do not fail, on the host setting

**Context**: A consumer may run QA on a host it does not administer. Failing every
run there until an operator acts blocks unrelated work; the detector lane already
gates the thing that matters. **Decision**: preflight prints the warning and the ini
line and continues. **Date**: 2026-09-10

### Decision 5: 8.4 is left unverified rather than measured

**Context**: Task 3.2 was to compile the fixture on PHP 8.4 and pin the lower bound of
the affected range. The development host runs only the affected 8.5, so this needs a
second runtime installed. **Why it is not worth it**:

1. **Neither answer changes any behaviour here.** The lane and the advisory gate on
   `FIRST_AFFECTED = 8.5.0`. If 8.4 is clean, that gate is already right; if 8.4 is
   affected, the only code that would care is a version of this lane on the `php8.4`
   branch, and there isn't one.
2. **There is no live 8.4 line to serve.** `php8.4` and `php8.3` are *ancestors* of
   `php8.5` — fully contained in it, with zero commits unique to either. They are
   historical snapshots, not parallel maintenance branches, so there is nothing to
   back-port to and no divergence to keep in sync.
3. **The upstream report does not need it.** "Not verified on 8.4" is a normal and
   honest thing to state in a php-src issue; maintainers bisect versions themselves and
   have every build to hand. Doing their bisection for them buys nothing.
4. **A host change is the most expensive way to buy nothing.** Adding a runtime to a
   development host to answer a question with no consequent action is a poor trade.

**Decision**: close the task. Record 8.4 as unverified — which is the accurate claim —
in `OpcacheDefects`, the dossier and the upstream report, and take the answer from
upstream's own bisect if and when the issue is triaged. Revisit only if this lane is
ever ported to a branch that actually targets 8.4. **Date**: 2026-09-10

## Success Criteria

- [x] The dossier's mutant shape fails the lane with the file, the function and its
  line range; the original shape passes.
- [x] On PHP 8.5.10 with the default mask the preflight warning appears; with bit
  `0x20` cleared it does not.
- [x] Documented under `docs/tools/opcache.md` with a stable identifier resolvable by
  `vendor/bin/rule-doc`.
- [x] Full unfiltered pipeline exit 0.

## Risks & Mitigations

| Risk                                                                              | Impact | Probability | Mitigation                                                                                                                          |
| --------------------------------------------------------------------------------- | ------ | ----------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| The detector's per-file compile is slow on large consumers                        | Med    | Med         | Measure in Task 1.1; the lane is in the linting phase where parallel-lint already spawns per-file processes, so reuse that batching |
| Upstream fixes it and the gate keeps running for nothing                          | Low    | High        | Version-gated lane and probe; closing the range is one constant                                                                     |
| The scan pattern misses a comparison opcode the optimizer can also leave unfolded | Med    | Low         | The fixture set is the dossier's idiom matrix; extend it as new shapes are found                                                    |
| A hand-written instance exists in a consumer today that the scan did not see      | High   | Low         | The scan covered three consumers' full `src/`; the lane makes it a standing check                                                   |

## Delivery & Milestones

- Investigation complete, dossier and plan recorded: (this commit)
