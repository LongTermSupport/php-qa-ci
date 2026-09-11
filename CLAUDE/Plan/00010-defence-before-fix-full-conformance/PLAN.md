# Plan 00010: Defence Before Fix — full conformance

**Status**: In Progress
**Created**: 2026-09-11
**Owner**: Joseph Edmonds
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Sub-Agent Orchestration

## Overview

php-qa-ci is the PHP reference toolchain for Defence Before Fix, and it does not fully
conform to the specifications it references. That is a defensible position — clause 9.2
says the declaration is the claim and the known-gap record, not a condition of
conformance — but it is not a resting place for the reference implementation. This plan
closes the gaps until both `known-gaps` lists in `composer.json` are empty and the
declaration claims conformance without qualification.

The target is not our own opinion of ourselves. Upstream publishes a clause-by-clause
audit of php-qa-ci, checked by running the commands in a checkout, at
[remote-docs/…/tools/php-qa-ci.md](../../../remote-docs/defence-before-fix.github.io/tools/php-qa-ci.md).
That audit is **harsher than our own declaration**, which is itself the first finding:
it records failures and partials that our `known-gaps` does not mention. An understated
gap record weakens the one thing clause 9.2 actually requires of us, so reconciling the
declaration with the audit comes before fixing anything.

Most of what remains is one shape of problem wearing different hats: **a defence that
cannot name itself, or cannot explain itself once named.** PHPArkitect prints prose with
no identifier, fifteen bundled rules resolve to a summary and no page, the lane listing
names every lane but offers no route to read about one, the release guard accepts an index
row instead of a page, and the agent block written into consuming projects carries no
lines for the defences actually active there. Fix identity and resolution properly and
most of the clause table turns green at once.

## Goals

- `composer.json` `extra.defence-before-fix` carries **empty** `known-gaps` at both the
  artefact and the project level, with every clause upstream grades `No` or `Partial`
  either closed or carrying a recorded Owner decision.
- Every defence php-qa-ci routes prints a stable identifier, and every identifier it can
  print resolves offline, through `bin/rule-doc`, to a page stating the correct
  construction — not a summary.
- No suppression route bypasses the project record, including the two baseline routes
  that currently do.
- The next upstream audit finds nothing our own declaration did not already admit.

## Non-Goals

- **Changing a specification to fit the tool.** We own that repository too, which makes
  this the easiest possible cheat and therefore the one to name. A clause we disagree
  with is changed in the specification repository, on its own merits, through its
  `ACCEPTANCE.md` cold-reader process — never as a move inside this plan. The copies
  under `remote-docs/` are read-only captures.
- **Grading ourselves generously.** The register entry is the scorecard. We may find gaps
  it missed and must record them; we do not award ourselves passes it withheld.
- **Rewriting PHPArkitect or PHPStan.** Where a wrapped detector cannot be made
  conformant, the honest outcomes are to wrap it better, to stop routing bundled
  defences through it, or to record an Owner decision — not to claim it passes.
- **A conformance push that weakens defences.** Nothing here removes a rule, widens a
  narrowing, or adds a suppression to make a clause easier.

## Context & Background

- The method, detector and toolchain specifications, vendored verbatim with provenance:
  [remote-docs/defence-before-fix.github.io/](../../../remote-docs/defence-before-fix.github.io/).
  Refresh with `.claude/hooks-daemon/bin/hooks-daemon remote-docs refresh --all`.
- Why the method is shaped as it is, and how it binds work in this repo:
  [CLAUDE/DefenceBeforeFix.md](../../DefenceBeforeFix.md). That file stays the single
  source of truth for the philosophy; this plan does not restate it.
- The register entry was written against branch `php8.4` at commit `e25aba4`
  (2026-09-08), and `php8.5` had already moved. Task 1.1's reconciliation, and the
  evidence for every verdict below, is in
  [JOURNAL/00010-Journal-26-09-11.md](JOURNAL/00010-Journal-26-09-11.md); the resulting
  ten-entry artefact list is in `composer.json` and is the working scope.

## Tasks

### Phase 1: Make the declaration honest before making it shorter

- [x] ✅ **Task 1.1**: Reconcile `composer.json` `known-gaps` against the register entry,
  clause by clause, on the current `php8.5` tree, by re-running the commands rather than
  reasoning from the text. Two gaps had already closed, one half closed, four were
  missing from our declaration; artefact list six entries → ten, project three → six.
  Evidence and the enumerated fifteen undocumented rules:
  [JOURNAL/00010-Journal-26-09-11.md](JOURNAL/00010-Journal-26-09-11.md).
- [ ] ⬜ **Task 1.2**: Add a defence over the declaration itself — the gap record is a
  claim about this repository, and nothing currently detects it drifting from reality.
  Decide (per [tool-boundaries.md](../../tool-boundaries.md)) whether this is a new lane
  or an assertion inside an existing one; the likely answer is an assertion.

### Phase 2: Identity — every defence names itself (toolchain 4.1, 5.1)

- [x] ✅ **Task 2.1**: Give the identifier-less lanes stable identifiers. **Already done
  before this plan existed**; the sweep of all thirty lanes found no defence without one.
  Kept rather than deleted so the next reader of the register entry does not re-open it.
  - [ ] ⬜ **Still owed**: a defence over it. Nothing fails the build when a new lane
    ships without an identifier, which is how five of them got there — the instance was
    fixed and the class left undefended. Fold into Task 3.2's guard, which already has to
    walk every defence.
- [x] ✅ **Task 2.2**: Give each lane a documentation route in `bin/rules`. Every lane now
  prints its identifier and the page it resolves to, and
  `testEveryLaneWithAnIdentifierResolvesToAnExistingPage` holds it. Surfacing the route
  found one lane resolving to nothing — `sensitiveParameterUsage`, whose index row is
  correct but whose link text contains a bracket `RuleDocResolver` could not parse.
  Committed red (7359454) before the fix.
- [ ] ⬜ **Task 2.3**: Decide what to do about **PHPArkitect**, which is the hardest
  clause in the set: as wrapped it fails detector 4.3 (prose, no identifier), 5.2 (no
  single-file run) and 6.1–6.3 (no resolver for its tier), and the default tier routes
  bundled defences through it.
  - [ ] ⬜ Establish whether the lane can attach identifiers to the bundled tier's rules
    and resolve them — arkitect's own output is prose, so this likely means the lane
    parsing violations and mapping them to tier rule identifiers.
  - [ ] ⬜ If it cannot be made conformant, the honest alternatives are to stop routing
    *bundled* defences through it (leaving it available for project rules) or to record
    an Owner decision. **Owner decision either way** — this changes a shipped default.

### Phase 3: Resolution — every identifier reaches a correct construction (toolchain 4.2, 8.1)

- [ ] ⬜ **Task 3.1**: Write remediation pages for the fifteen bundled PHPStan rules that
  resolve to an index row and no page. Each states what the rule is about, why it exists,
  and the correct construction — `docs/phpstan-rules/` house style, per method 3.6.
  - [ ] ⬜ Enumerate the fifteen from `bin/rules .` output (`doc: no documentation page`)
    rather than from the audit's count, which is a snapshot.
  - [ ] ⬜ Parallelisable: one sub-agent per rule, each reading the rule source and its
    tests. Review every page — a generated page that restates the summary is the defect
    this task exists to remove.
- [ ] ⬜ **Task 3.2**: Tighten the release guard (`RuleDocumentationTest`) from "an index
  row exists" to "a page exists and states a correct construction", so 8.1 holds and
  Task 3.1 cannot silently regress. Red first, on a rule with no page. **The lane half is
  already done** — Task 2.2's `testEveryLaneWithAnIdentifierResolvesToAnExistingPage`
  asserts the resolved path is a real file; this task copies that shape for rules.
- [ ] ⬜ **Task 3.3**: Decide the **PHPStan native catalogue** question (detector 6.2/6.3
  as wrapped): `bin/rule-doc method.notFound` answers `Unknown rule identifier`, and
  PHPStan's own identifiers document online only.
  - [ ] ⬜ Options to cost: ship a resolver mapping native identifiers to phpstan.org
    pages (fails "without network access"); vendor a catalogue under `remote-docs/` and
    resolve against it; or record an Owner decision that the native catalogue is out of
    scope. **Owner decision**: the first is not conformance, the second is real work with
    a staleness cost, the third is a permanent recorded gap.

### Phase 4: Record — no suppression route bypasses it (toolchain 4.3, 6.2)

- [ ] ⬜ **Task 4.1**: Close the `phparkitect-baseline.json` route. A baseline generated
  once is read silently on every later run — upstream reproduced `Baseline file found` /
  `No violations detected` on a fixture holding a violation. The lane must refuse it, or
  surface it in the record and the listing. Red first, with that fixture.
- [ ] ⬜ **Task 4.2**: Make the `phpstanIgnoreJustification` lane read the **whole
  resolved neon chain**, not `qaConfig/phpstan.neon` alone, so an `ignoreErrors` entry or
  a baseline reached through an `includes:` cannot escape justification. This is one gap
  counted twice, under 4.3 and 6.2. Red first, with an included file carrying an
  unjustified entry.
- [ ] ⬜ **Task 4.3**: Configure PHPStan's `reportIgnoresWithoutComments` (detector 7.2,
  graded `No`), or record why the justification lane standing in for it is sufficient.
  Prefer configuring it: defence in depth costs nothing here.

### Phase 5: Agent context and defaults (toolchain 6.4, 7.1)

- [ ] ⬜ **Task 5.1**: Put the active defences into the agent block the plugin writes into
  each consuming project's `CLAUDE.md`. It currently carries a pointer and no rule lines,
  which is toolchain 7.1 graded `No`. `bin/rules --json` already produces the data; the
  work is rendering it, bounding its size, and keeping it fresh on install/update.
- [ ] ⬜ **Task 5.2**: State the toolchain's own defaults for what the method leaves to
  the project (toolchain 6.4) — the sweep scope, what counts as generated or vendored,
  and the calibrations. Where `docs/tools/` states a lane default already, link rather
  than restate.

### Phase 6: Claim it

- [ ] ⬜ **Task 6.1**: Empty both `known-gaps` lists, or reduce each remaining entry to a
  recorded Owner decision, and bump the declared versions to the specifications actually
  vendored under `remote-docs/`.
- [ ] ⬜ **Task 6.2**: Re-audit and update the register entry in the DBF repository.
  **Both repositories are first-party** (`Defence-Before-Fix` and `LongTermSupport` are
  both Joseph Edmonds), so this is a commit we can make, not a request we file. The
  separation is editorial discipline, not an access boundary — see Decision 4.
  - [ ] ⬜ Re-audit by *running* the commands, as the original did, and record the
    evidence column the register format requires.
  - [ ] ⬜ **Per-post Owner authorisation still applies** to anything that lands in a
    public repository — see [CLAUDE/segfault-policy.md](../../segfault-policy.md) step 3
    for the same constraint stated for php-src.

## Dependencies

- Related: Plan 00005 (the lane registry and `PipelineBuilder` Phase 2 extends).
- Related: [CLAUDE/tool-boundaries.md](../../tool-boundaries.md) governs every "is this a
  new lane or an assertion" question in Phases 1, 2 and 4.

## Technical Decisions

### Decision 1: Upstream's register entry is the scorecard, not our own declaration

**Context**: we already keep a `known-gaps` list, so the obvious plan is "close our list".
**Why that is wrong**: our list is shorter than upstream's audit, and the difference is
not in our favour — it omits at least five clauses upstream grades `No` or `Partial`.
Planning against our own list would bake the understatement in and produce a declaration
that claims conformance while the published register still says otherwise. **Decision**:
plan against the register entry, reconcile our declaration to it first (Task 1.1), and
treat any gap we find that upstream missed as an addition to record rather than a
discretionary one. **Date**: 2026-09-11

### Decision 2: Identity before documentation before enforcement

**Context**: the gaps could be attacked in any order, and the fifteen missing pages are
the most visible. **Why this order**: a page for a defence that cannot name itself is
unreachable — method 3.6 requires the identifier to be the route in. Writing pages first
would produce documentation nothing resolves to, and tightening the release guard first
would fail the build on gaps not yet closed. So identity (Phase 2), then resolution
(Phase 3), then the guard that holds both (Task 3.2). **Date**: 2026-09-11

### Decision 3: PHPArkitect and the PHPStan native catalogue are Owner decisions, not ours

**Context**: both could be "closed" by narrowing what we claim to route. **Why it is the
Owner's**: dropping the bundled arkitect tier changes a shipped default for every
consumer, and declaring the native catalogue out of scope is accepting a permanent gap.
[DefenceBeforeFix.md](../../DefenceBeforeFix.md) reserves both of those to the Owner —
"deciding a defensible class will not be defended" and "accepting a known unfixed
instance". **Decision**: Tasks 2.3 and 3.3 cost the options and stop; they do not choose.
**Date**: 2026-09-11

### Decision 4: First-party on both sides, and the separation is kept anyway

**Context**: `Defence-Before-Fix` and `LongTermSupport` are the same author, so "upstream"
here means another repository, not another party. Nothing stops us editing the
specifications, the register entry or our own grade. **Why keep the separation**: the
register grades PHPStan, Psalm, ESLint, Semgrep, CodeQL and a few dozen others, and its
entry for php-qa-ci says it grades this tool "with the same scrutiny as every other entry".
That sentence is the asset. A specification written to be passed by its author's tool, and
a register that flatters it, are worth nothing to anyone — including us, since the gaps it
found are real and we did not find them ourselves. **Decision**: treat the specification
and the register as though they belonged to someone else. Conformance is earned by changing
php-qa-ci, and a specification change is argued in that repository on its own merits under
its cold-reader acceptance process. Being able to cheat is exactly why it is written down.
**Date**: 2026-09-11

## Success Criteria

- [ ] Every clause upstream grades `No` or `Partial` is either graded `Yes` by the same
  evidence, or carries a recorded Owner decision in `known-gaps`.
- [ ] `bin/rules .` shows an identifier and a documentation route for every rule **and**
  every lane, with no `doc: no documentation page` rows.
- [ ] `bin/rule-doc <identifier>` resolves every identifier php-qa-ci can print to a page
  stating a correct construction, offline.
- [ ] A baseline — PHPArkitect's or PHPStan's, at the top level or through an
  `includes:` — cannot suppress a finding without appearing in the record and the
  listing.
- [ ] The full battery passes ([prepush-verification.md](../../prepush-verification.md)).

## Risks & Mitigations

| Risk                                                          | Impact | Probability | Mitigation                                                                                                                      |
| ------------------------------------------------------------- | ------ | ----------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Fifteen pages get written as filler that restates the summary | High   | High        | Task 3.1 requires a correct construction per page and human review; Task 3.2's guard is what makes filler fail rather than pass |
| PHPArkitect cannot be made conformant as wrapped              | High   | Med         | Task 2.3 costs the alternatives and hands the Owner a decision rather than quietly claiming a pass                              |
| The conformance push becomes a reason to weaken a defence     | High   | Low         | Stated as a Non-Goal; every suppression and narrowing is already an Owner decision under DefenceBeforeFix.md                    |
| The register entry moves while we work against it             | Med    | Med         | It is vendored with a staleness window; `remote-docs check` reports when it needs refreshing                                    |
| Declaring conformance before upstream re-audits               | Med    | Med         | Task 6.2 makes the re-audit the closing step, and 9.2 means the declaration is a claim we must be able to defend                |

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00010-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan filed, specs and register entry vendored with provenance: (this commit)
