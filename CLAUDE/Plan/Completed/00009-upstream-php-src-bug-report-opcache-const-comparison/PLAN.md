# Plan 00009: Upstream php-src bug report — OPcache const-vs-const comparison

**Status**: Complete
**Created**: 2026-09-10
**Owner**: Joseph Edmonds
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Single-Threaded

## Overview

Plan 00007 found, proved and defended against an OPcache optimizer defect: the DFA/SCCP
pass can leave a comparison opcode with two constant operands, which the VM has no
handler for, so the fallback handler reads a constant index as a variable slot and either
segfaults or compares unrelated memory. 00007 shipped php-qa-ci's own defences — the
`opcache` lane and the preflight advisory — and left one task open: telling upstream.

This plan is that task, split out because it is a different kind of work with a different
audience. Nothing here changes php-qa-ci's behaviour. The deliverable is a php-src issue
that a maintainer can act on without asking a single follow-up question, plus the
follow-through: the issue number recorded where the next reader of the affected-range
constant will find it, and `FIRST_FIXED` closed when a fix ships.

Two things have already moved the report forward from 00007's draft. The reproduction is
now a **deterministic segfault** from six lines with `-n` (no php.ini, no extension but
the built-in OPcache), not merely a dump containing the bad opcode — which removes the
weakest part of the draft. And how php-src wants a bug filed is now written down in
[filing-guide.md](filing-guide.md), including the one LLM rule it does have.

## Goals

- A php-src issue exists, with a self-contained reproduction that segfaults on an
  unmodified stock build, and it is not closed for want of information.
- The issue number is recorded in [Plan 00007's report](../00007-opcache-optimizer-const-comparison-crash/upstream-report.md)
  and in `OpcacheDefects`' doc comment, so the affected-range constant leads to it.
- Filing follows php-src's own stated conventions, including its LLM-disclosure request,
  rather than a guess at them.

## Non-Goals

- **Writing the C fix.** Optimizer internals are maintainer territory and a fix is not
  a precondition for a useful report. Whether to attempt one afterwards is Task 3.2.
- **Changing any php-qa-ci behaviour.** The lane, the advisory and the affected range
  were delivered under Plan 00007 and are not reopened here — except for the one-line
  `FIRST_FIXED` edit in Task 3.3 once upstream ships a fix.
- ~~**Filing under this project's identity.**~~ Withdrawn: the owner ruled that
  `LTSCommerce` is the correct filer, so this plan posts as well as prepares. See
  Decision 4.

## Context & Background

- The defect, the evidence and the reasoning:
  [Plan 00007](../00007-opcache-optimizer-const-comparison-crash/PLAN.md) and its
  [investigation dossier](../00007-opcache-optimizer-const-comparison-crash/investigation.md).
- The text to post, in the issue form's own field order:
  [00007's upstream-report.md](../00007-opcache-optimizer-const-comparison-crash/upstream-report.md).
  That file stays the single copy of the report; this plan does not hold a second one.
- How to post it: [filing-guide.md](filing-guide.md).
- The reproduction as a runnable file: [reproducer.php](reproducer.php).

## Tasks

### Phase 1: Make the report unanswerable

- [x] ✅ **Task 1.1**: Reduce the reproduction to the smallest code that *crashes*, not
  merely the smallest that emits the bad opcode.
  - [x] ✅ Dropped `declare(strict_types=1)`, the parameter type and the return type; the
    crash survives all three.
  - [x] ✅ Established what it does need: a value-returning branch on both sides (with the
    returns removed the branch is optimised away and nothing crashes), the `null`
    argument at the call site (`f([9])` never reaches the opcode), and the file being
    older than `opcache.file_update_protection` or that setting being `0`.
  - [x] ✅ Confirmed under `-n`, so the report can claim no php.ini and no extension
    beyond the built-in OPcache — which also retires the draft's weaker "Xdebug was not
    loaded in the crashing processes" phrasing.
  - [x] ✅ Re-ran the mask bisect against the crash rather than the dump: default mask
    segfaults, `0x7FFEBFDF` and `0` print `int(1)`.
- [x] ✅ **Task 1.2**: Establish php-src's filing conventions from php-src itself and
  record them in [filing-guide.md](filing-guide.md): public issue vs security advisory,
  the template's required fields, the "skip the theatrics" house style, the LLM
  disclosure rule, and what a follow-up PR would have to look like.
- [x] ✅ **Task 1.3**: Rewrite [00007's upstream-report.md](../00007-opcache-optimizer-const-comparison-crash/upstream-report.md)
  around the crashing reproduction and the form's field order.
- [x] ✅ **Task 1.4**: Settle the 3v4l.org question the template raises. **Answer: no link
  is possible, and nothing needs publishing to establish that.** `opcache.enable_cli` is
  `INI_SYSTEM` and defaults to `0`, so a snippet cannot enable the optimizer from inside
  itself, and 3v4l exposes no per-run ini. The report now says so in one clause rather
  than omitting the field silently.
- [x] ✅ **Task 1.5**: Decide what the report claims about **8.4 and master**. **Answer:
  claim neither, which is what the report does** — 00007 Decision 5 stands.
  - [x] ✅ Recorded what has changed since that ruling, so a revisit is informed rather
    than reflexive: PHP-8.4 is the lowest *actively* supported branch, so it is where a
    fix would land, and `Zend/Optimizer` has no PHP-8.5 commit and no relevant master
    commit since 8.5.10 — master is very probably affected too.
  - [x] ✅ Costed the alternative: verifying means installing a C toolchain (this
    container has none) and building two php-src branches. Not worth it unattached to a
    fix PR, since "not verified" is honest and maintainers bisect for themselves.
  - [x] ✅ **Answered by upstream, as Decision 5 predicted it would be.** The maintainer's
    verdict on the issue is "since 8.5, because of the empty array optimization in the
    VM", and the fix PR is based at `PHP-8.5` — so 8.4 is unaffected and `FIRST_AFFECTED`
    was right. The revisit trigger (verify 8.4 before opening a fix PR) never fired,
    because Task 3.2 resolved to "someone else's PR".
- [x] ✅ **Task 1.6**: Re-run the duplicate search immediately before filing. Nothing
  matched: "constant comparison optimizer", "IS_NOT_IDENTICAL const" and
  "zval_undefined_cv opcache" all return empty, the `zval_undefined_cv` crashes on the
  tracker are tracing-JIT, and the six open `Category: Optimizer` issues are unrelated
  (the nearest, "Unsound SCCP for partial objects with hooks", is a different defect).

### Phase 2: File it

- [x] ✅ **Task 2.1**: Filed as <https://github.com/php/php-src/issues/23644>, from this
  session under the `LTSCommerce` identity (see Decision 4).
  - [x] ✅ Applied the LLM-disclosure rule as a quoted footer naming the prose as
    LLM-assisted and the reproduction, dump, bisect and version details as measurements.
- [x] ✅ **Task 2.2**: Issue number recorded in three places, so the trail runs both
  ways: the report's status line, `OpcacheDefects`' class doc comment beside the
  affected-range constants, and 00007 Task 3.1.

### Phase 3: Follow-through

- [x] ✅ **Task 3.1**: Watch triage and answer questions. Triaged inside a day to
  `Bug` / `Category: Engine` / `Status: Verified`, with no question asked of us — the
  first reply was a root-cause analysis and a patch. None of the anticipated asks (a
  core dump, a `--enable-debug` build, a check on another branch) materialised.
- [x] ✅ **Task 3.2**: Decide whether to attempt a fix PR. **Moot: a third party opened
  <https://github.com/php/php-src/pull/23648> carrying the maintainer's patch, a `NEWS`
  entry and `ext/opcache/tests/opt/gh23644.phpt` — whose first test function is our
  reproducer verbatim.** Nothing left for us to contribute; opening a competing PR would
  be noise.
- [x] ✅ **Task 3.3**: `FIRST_FIXED` is defended, not remembered. PR 23648 is open and the
  8.5.x that carries it does not exist yet, and no reminder in a plan survives that wait.
  `tests/Large/Opcache/OpcacheDefectRangeTest.php` (recorded under 00007 Task 3.1) compiles
  the filed shape through whatever PHP runs the suite and fails the moment the constants and
  the interpreter disagree, naming the version to set. The remaining one-line edit is
  demanded by the build on the first fixed PHP; it needs no plan to hold it open.
- [x] ✅ **Task 3.4**: Correct our own account of the defect where upstream's differs.
  The missing `NO_CONST_CONST` marker on `ZEND_IS_IDENTICAL_EMPTY_ARRAY` /
  `ZEND_IS_NOT_IDENTICAL_EMPTY_ARRAY` is the defect; the unfolded comparison upstream
  treats as a missed optimisation. Updated in `OpcacheDefects`' doc comment and
  [docs/tools/opcache.md](../../../docs/tools/opcache.md). The lane's *observable* — a
  comparison opcode with two constant operands — is unchanged and still correct.
- [x] ✅ **Task 3.5**: Generalise the episode into standing policy, on the owner's
  instruction: **any** PHP segfault is halt → debug → file → defend, never something to
  retry past or route around. Written as [CLAUDE/segfault-policy.md](../../segfault-policy.md)
  with this pair of plans as the worked archetype, and made binding from `CLAUDE.md`.
  It carries the container caveat too: `coredumpctl` inside the container is empty by
  design because the kernel and core pattern are the host's, so the kernel log and the
  core must be requested from the host along with matching debug symbols. The policy is
  repo-wide rather than 00009's, so it lives in `CLAUDE/`, not in this folder.

## Dependencies

- Sub-plan of: Plan 00007 (In Progress) — this plan *is* its Task 3.1.
- Blocks: Plan 00007 completing, since 3.1 is its last open task.

## Technical Decisions

### Decision 1: The report is split out of 00007 rather than finished inside it

**Context**: 00007 Task 3.1 already existed and already had a draft, so a sub-plan needs
justifying. **Why split**: the remaining work is not php-qa-ci work. It runs on an
external tracker, on someone else's schedule, under a different identity, and its last
task (`FIRST_FIXED`) cannot happen until upstream ships a release. Leaving it inside
00007 keeps that plan open indefinitely for a task nobody here controls, and buries the
filing conventions inside a plan about a detector lane. **Decision**: 00009 owns filing
and follow-through; 00007 keeps the evidence and the report text and closes when 00009
delivers. **Date**: 2026-09-10

### Decision 2: The report text stays in 00007, not copied here

**Context**: the obvious move is to hold "the text to paste" in the plan that posts it.
**Why not**: two copies of a report drift, and the one that gets edited is never reliably
the one that gets pasted. **Decision**: `00007/upstream-report.md` remains the single
copy and this plan links to it; 00009 owns the *process* (how php-src wants it, whether
3v4l applies, what to claim about other branches) and the runnable reproducer.
**Date**: 2026-09-10

### Decision 3: File a public issue, not a security advisory

**Context**: it is a segfault, and a segfault reflexively suggests the private security
route. **Why public**: php-src's `SECURITY.md` classifies memory violations reached by
executing PHP source as bugs rather than security issues — PHP does not sandbox, so
anyone who can supply the source already has code execution, and no boundary is crossed.
**Decision**: public issue via the Bug report template; the advisory form is the fallback
only if a triager reclassifies it. Reasoning and citation in
[filing-guide.md](filing-guide.md) section 1. **Date**: 2026-09-10

### Decision 4: Filed from this session as `LTSCommerce`, not a separate identity

**Context**: 00007 Task 3.1 said the report would be "filed by the owner under a
different agent/GitHub identity", which made Phase 2 unreachable from here and left the
plan blocked on a human step. **What changed**: the owner ruled that constraint was an
assumption of the agent that wrote 00007, not a standing policy, and that this session's
authenticated identity — `LTSCommerce` (Joseph Edmonds), the owner's own account — is the
correct filer. **Decision**: file from here. The report was unchanged by this; only who
pressed the button changed, and the LLM-disclosure footer means the account is not
passing generated prose off as hand-written. **Date**: 2026-09-10

## Success Criteria

- [x] The issue is open on php/php-src with a reproduction a maintainer can run unchanged.
- [x] No maintainer reply asks for something Phase 1 could have supplied — an isolated
  case, a backtrace, the ini settings, or which optimizer pass is responsible. The first
  reply was a root-cause analysis and a patch, not a question.
- [x] The issue number appears in the report, in `OpcacheDefects` and in 00007 Task 3.1.
- [ ] `FIRST_FIXED` is set once upstream ships the fix, or this plan is closed with that
  one task explicitly handed back to 00007 if the wait outlives it.

## Risks & Mitigations

| Risk                                                            | Impact | Probability | Mitigation                                                                                                                                            |
| --------------------------------------------------------------- | ------ | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| The report reads as generated slop and is ignored               | High   | Med         | Six-line crashing reproducer up front, no impact essay, LLM prose quoted per the project's own rule ([filing-guide.md](filing-guide.md) sections 3-4) |
| A maintainer asks about 8.4 or master and we have no answer     | Med    | High        | Task 1.5 decides the claim in advance and states "not verified" rather than guessing; a build is a costed follow-up                                   |
| The bug is already fixed in master and the issue is a duplicate | Low    | Low         | Task 1.6 re-checks the tracker and `Zend/Optimizer`'s history just before filing                                                                      |
| Upstream takes a long time, leaving this plan open              | Low    | High        | Task 3.3 allows closing with `FIRST_FIXED` handed back to 00007 rather than holding the plan open for a release                                       |

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00009-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Crashing reproduction, filing guide and rewritten report: (this commit)
- Filed as <https://github.com/php/php-src/issues/23644>, Status: Verified, fix proposed in
  PR 23648 carrying our reproducer; `CLAUDE/segfault-policy.md` produced from the episode
- `FIRST_FIXED` defended by `OpcacheDefectRangeTest`; plan closed
