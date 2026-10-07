# Plan 00010: Technical Decisions

The decisions taken in [Plan 00010](PLAN.md), numbered as `composer.json` `extra.defence-before-fix` cites them.

## Decision 1: Upstream's register entry is the scorecard, not our own declaration

**Context**: we already keep a `known-gaps` list, so the obvious plan is "close our list".
**Why that is wrong**: our list is shorter than upstream's audit, and the difference is
not in our favour — it omits at least five clauses upstream grades `No` or `Partial`.
Planning against our own list would bake the understatement in and produce a declaration
that claims conformance while the published register still says otherwise. **Decision**:
plan against the register entry, reconcile our declaration to it first (Task 1.1), and
treat any gap we find that upstream missed as an addition to record rather than a
discretionary one. **Date**: 2026-09-11

## Decision 2: Identity before documentation before enforcement

**Context**: the gaps could be attacked in any order, and the fifteen missing pages are
the most visible. **Why this order**: a page for a defence that cannot name itself is
unreachable — method 3.6 requires the identifier to be the route in. Writing pages first
would produce documentation nothing resolves to, and tightening the release guard first
would fail the build on gaps not yet closed. So identity (Phase 2), then resolution
(Phase 3), then the guard that holds both (Task 3.2). **Date**: 2026-09-11

## Decision 3: PHPArkitect and the PHPStan native catalogue are Owner decisions, not ours

**Context**: both could be "closed" by narrowing what we claim to route. **Why it is the
Owner's**: dropping the bundled arkitect tier changes a shipped default for every
consumer, and declaring the native catalogue out of scope is accepting a permanent gap.
[DefenceBeforeFix.md](../../../DefenceBeforeFix.md) reserves both of those to the Owner —
"deciding a defensible class will not be defended" and "accepting a known unfixed
instance". **Decision**: Tasks 2.3 and 3.3 cost the options and stop; they do not choose.
**Date**: 2026-09-11

## Decision 4: First-party on both sides, and the separation is kept anyway

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

## Decision 5: PHPArkitect stays as shipped; its clauses are an accepted gap

**Context**: the arkitect lane fails detector 4.3, 5.2 and 6.1–6.3 as wrapped, and Task 2.3
asked whether to make it conform, stop routing bundled defences through it, or accept the
gap. **Why accept**: conformance would mean parsing arkitect's prose output into rule
identifiers — a second implementation of a format we do not own, broken silently by every
upstream wording change, which is a worse defect than the one it closes. Dropping the
bundled tier would remove real structural defences from every consumer to improve a
scorecard, which the Non-Goals forbid. The lane itself is identified and documented; what is
missing is per-rule identity inside it. **Decision**: keep the shipped default, keep the
`known-gaps` entries, and word them as accepted rather than pending. Taken by the Owner's
instruction to resolve the open blockers; reversible by reopening Task 2.3. **Date**:
2026-09-12

## Decision 6: PHPStan's native catalogue is not carried offline

**Context**: `bin/rule-doc` could not say anything useful about an identifier that is not
ours. **Why not vendor the catalogue**: phpstan.org documents hundreds of identifiers and
changes them with every PHPStan release; a vendored copy would be stale within its own
`remote-docs` window and would be a second maintenance burden with no defect behind it.
**Decision**: `bin/rule-doc` names the catalogue a foreign identifier belongs to and says the
package does not carry it offline; the detector 6.2/6.3 gap for PHPStan-as-wrapped stays
declared. Taken by the Owner's instruction to resolve the open blockers. **Date**: 2026-09-12

## Decision 7: Two link checkers are kept, and `phpqaci.forbiddenAttribute` is not renamed

**Context**: both surfaced during Tasks 2.2–3.2. The hooks daemon's `pointer-resolves`
overlaps the `markdownLinks` lane on this checkout; and `phpqaci.forbiddenAttribute` names a
category rather than the attribute it forbids. **Decision**: the checkers answer at different
moments for different audiences (a guardrail for the editor; the package's guarantee to every
consumer), so both stay, and [docs/tools/markdownLinks.md](../../../../docs/tools/markdownLinks.md)
says why. The identifier stays: it is published, and a consumer may already carry it in
`ignoreErrors`, so a rename is a breaking change with no defect behind it. Both taken by the
Owner's instruction to resolve the open blockers. **Date**: 2026-09-12
