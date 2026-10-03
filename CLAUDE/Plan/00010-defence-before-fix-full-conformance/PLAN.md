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
- [x] ✅ **Task 1.2**: Add a defence over the declaration itself — the gap record is a
  claim about this repository, and nothing currently detects it drifting from reality.
  Decide (per [tool-boundaries.md](../../tool-boundaries.md)) whether this is a new lane
  or an assertion inside an existing one; the likely answer is an assertion. An assertion,
  in `DefenceBeforeFixDeclarationTest`: the declaration describes this repository, not a
  consumer's, so it is not a lane. Versions read from the vendored specifications; each
  gap's clause must exist; each gap is accepted (citing a recorded decision with a probe
  that the gap is still real) or open (citing an active plan). Red at 09457a7.

### Phase 2: Identity — every defence names itself (toolchain 4.1, 5.1)

- [x] ✅ **Task 2.1**: Give the identifier-less lanes stable identifiers. **Already done
  before this plan existed**; the sweep of all thirty lanes found no defence without one.
  Kept rather than deleted so the next reader of the register entry does not re-open it.
  - [x] ✅ **Still owed**: a defence over it. Nothing fails the build when a new lane
    ships without an identifier, which is how five of them got there — the instance was
    fixed and the class left undefended. `EveryLaneNamesItselfTest`: every registry entry
    that is neither a phase runner nor another tool's mode has a shipped implementation with
    a `phpqaci.` identifier, proved on a fabricated registry with an unimplemented lane and
    a foreign identifier.
- [x] ✅ **Task 2.2**: Give each lane a documentation route in `bin/rules`. Every lane now
  prints its identifier and the page it resolves to, and
  `testEveryLaneWithAnIdentifierResolvesToAnExistingPage` holds it. Surfacing the route
  found one lane resolving to nothing — `sensitiveParameterUsage`, whose index row is
  correct but whose link text contains a bracket `RuleDocResolver` could not parse.
  Committed red (7359454) before the fix.
- [x] ✅ **Task 2.3**: **PHPArkitect stays as shipped, and its clauses are a recorded
  Owner decision, not a pending fix** — Decision 5. As wrapped it fails detector 4.3
  (prose, no identifier), 5.2 (no single-file run) and 6.1–6.3 (no resolver for its tier).
  Parsing arkitect's prose into identifiers would be a second implementation of arkitect's
  own output format, owned here and broken by every upstream wording change; dropping the
  bundled tier would remove real structural defences to improve a scorecard. Neither is
  worth it. The lane carries `phpqaci.phpArkitect` and resolves to its page; the tier's
  individual rules do not, and the declaration says so.

### Phase 3: Resolution — every identifier reaches a correct construction (toolchain 4.2, 8.1)

- [x] ✅ **Task 3.1**: Write remediation pages for the bundled PHPStan rules that resolve to
  an index row and no page. Each states what the rule is about, why it exists, and the
  correct construction — `docs/phpstan-rules/` house style, per method 3.6.
  - [x] ✅ Enumerated from `bin/rules .` (`doc: no documentation page`) rather than the
    audit's count: **fourteen**, not fifteen. The audit's number was a snapshot, exactly as
    this sub-task anticipated.
  - [x] ✅ Written and reviewed page by page against each rule's source. `bin/rules .` now
    reports no `no documentation page` row for any rule **or** lane.
- [x] ✅ **Task 3.2**: Tighten the release guard (`RuleDocumentationTest`) from "an index row
  exists" to "a page exists", so 8.1 holds and Task 3.1 cannot silently regress. The guard
  reads what **source declares**, not what the neon bundles register, so it is strictly
  stronger than `bin/rules` — it immediately found four more gaps (the Symfony tier and one
  experimental rule) that Task 3.1's enumeration had missed. Red first, three ways,
  committed at 30ab7e1.
  - [x] ✅ **Owed guard closed**: "and states a correct construction". Each page needs a
    construction section (`The correct construction`, `How to fix a failure`, or `How to act on a report` for the informational lane) adding at least fifteen distinct words to the
    summary. Red at a70a1b2: six lane pages had no section, Twig Lint and Yaml Lint only
    "fix the file". Prose truth stays a reviewer's call; restating the summary now fails.
- [x] ✅ **Task 3.3**: **PHPStan's native catalogue is out of scope offline, as a recorded
  Owner decision** — Decision 6. `bin/rule-doc method.notFound` no longer answers
  `Unknown rule identifier`: it says the identifier is not php-qa-ci's, names the
  phpstan.org page it belongs to, and says plainly that the catalogue is not carried
  offline. That is an honest answer to a practitioner, not a claim of conformance —
  detector 6.2/6.3 remain declared gaps for that detector as wrapped. Vendoring PHPStan's
  catalogue under `remote-docs/` was costed and declined: hundreds of pages with a
  staleness window shorter than PHPStan's release cadence.

### Phase 4: Record — no suppression route bypasses it (toolchain 4.3, 6.2)

- [x] ✅ **Task 4.1**: Close the `phparkitect-baseline.json` route. A baseline generated
  once is read silently on every later run — upstream reproduced `Baseline file found` /
  `No violations detected` on a fixture holding a violation. The lane must refuse it, or
  surface it in the record and the listing. Red first, with that fixture. The lane passes
  `--skip-baseline` in both modes (phparkitect resolves the baseline from the CLI only, so
  nothing else can turn it back on) and names a present file as not read (red `e96d215`).
  Toolchain 4.3 closed at both levels
- [x] ✅ **Task 4.2**: Make the `phpstanIgnoreJustification` lane read the **whole
  resolved neon chain**, not `qaConfig/phpstan.neon` alone, so an `ignoreErrors` entry or
  a baseline reached through an `includes:` cannot escape justification. This is one gap
  counted twice, under 4.3 and 6.2. Red first, with an included file carrying an
  unjustified entry. `NeonIncludeChain` follows `includes:` and fails closed on what it
  cannot follow; a second escape found on the way, entries written inline as a flow list,
  is caught by comparing the decoded count with the `-` items read. 6.2 closed; 4.3 keeps
  only the PHPArkitect baseline (Task 4.1)
- [x] ✅ **Task 4.3**: Configure PHPStan's `reportIgnoresWithoutComments` (detector 7.2,
  graded `No`), or record why the justification lane standing in for it is sufficient.
  Prefer configuring it: defence in depth costs nothing here. On in `rules-default.neon`, so
  it reaches every consumer the bundled tier reaches, behind `inlinePhpstanIgnore` (red
  `5672d30`). No finding on this repository. Detector 7.2 closed at both levels

### Phase 5: Agent context and defaults (toolchain 6.4, 7.1)

- [x] ✅ **Task 5.1**: Put the active defences into the agent block the plugin writes into
  each consuming project's `CLAUDE.md`. It currently carries a pointer and no rule lines,
  which is toolchain 7.1 graded `No`. `bin/rules --json` already produces the data; the
  work is rendering it, bounding its size, and keeping it fresh on install/update.
  `ActiveDefencesSummary` renders one line per defence into a marked region
  (`AgentContextRegion`), written by `bin/rules --write-agent-summary` from the deploy and
  into this repository's own `CLAUDE.md`, held current by `AgentContextIsCurrentTest`.
  Found on the way: `bin/rules` read none of what `phpstan/extension-installer` delivers,
  which in a consumer is every bundled rule (red `e3aa01b`); it now does, by package.
  Toolchain 7.1 closed at both levels
- [x] ✅ **Task 5.2**: State the toolchain's own defaults for what the method leaves to
  the project (toolchain 6.4) — the sweep scope, what counts as generated or vendored,
  and the calibrations. Where `docs/tools/` states a lane default already, link rather
  than restate. [docs/defence-before-fix-defaults.md](../../../docs/defence-before-fix-defaults.md),
  linked from the consumer `CLAUDE.md` block, the identifier index and DefenceBeforeFix.md.
  Toolchain 6.4 closed

### Phase 6: Claim it

- [x] ✅ **Task 6.1**: Empty both `known-gaps` lists, or reduce each remaining entry to a
  recorded Owner decision, and bump the declared versions to the specifications actually
  vendored under `remote-docs/`. Three entries remain, all toolchain 4.1, each citing
  Decision 5 or 6; the vendored versions (method 1.0.1, toolchain 0.2.0) already matched
  and are now read from the vendored files by the Task 1.2 guard. Per toolchain 9.2 a
  non-empty record is not a claim of conformance, so the declaration still claims none.
- [ ] ⬜ **Task 6.2**: Re-audit and update the register entry in the DBF repository.
  **Both repositories are first-party** (`Defence-Before-Fix` and `LongTermSupport` are
  both Joseph Edmonds), so this is a commit we can make, not a request we file. The
  separation is editorial discipline, not an access boundary — see Decision 4.
  - [x] ✅ Re-audit by *running* the commands, as the original did, and record the
    evidence column the register format requires: [REAUDIT.md](REAUDIT.md), unpublished.
    Ten of twelve rows re-grade `Yes`; detector 6.2/6.3 and toolchain 4.1 stay `Partial`
    under Decisions 5 and 6.
  - [ ] ⬜ **Per-post Owner authorisation still applies** to anything that lands in a
    public repository — see [CLAUDE/segfault-policy.md](../../segfault-policy.md) step 3
    for the same constraint stated for php-src.

## Dependencies

- Related: Plan 00005 (the lane registry and `PipelineBuilder` Phase 2 extends).
- Related: [CLAUDE/tool-boundaries.md](../../tool-boundaries.md) governs every "is this a
  new lane or an assertion" question in Phases 1, 2 and 4.

## Technical Decisions

Seven decisions, each with its context and reasoning: [DECISIONS.md](DECISIONS.md).

## Success Criteria

- [x] Every clause upstream grades `No` or `Partial` is either graded `Yes` by the same
  evidence, or carries a recorded Owner decision in `known-gaps`.
- [x] `bin/rules .` shows an identifier and a documentation route for every rule **and**
  every lane, with no `doc: no documentation page` rows.
- [x] `bin/rule-doc <identifier>` resolves every identifier php-qa-ci can print to a page
  stating a correct construction, offline.
- [x] A baseline — PHPArkitect's or PHPStan's, at the top level or through an
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
