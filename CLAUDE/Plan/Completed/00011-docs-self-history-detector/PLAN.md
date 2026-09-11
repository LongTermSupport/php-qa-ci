# Plan 00011: docs self history detector

**Status**: Complete
**Created**: 2026-09-11
**Owner**: joseph
**Priority**: Medium

## Overview

A Defence Before Fix execution against the class **"a documentation file that describes
itself rather than its subject"**. The originating instances were written into this
repository's own `README.md` while rewriting two sections to point at their sources of truth:
rather than simply stating where the list now lives, the new prose narrated what the previous
draft of the section had contained. The Owner's ruling on seeing it: *"you don't journal
changes in the README — the README is for new people to READ; they do not care about history
of the document, that is a commit message."*

The hazard is not ugliness. A sentence about a page's own past is unfalsifiable from the page,
un-actionable for the reader, and decays in a way ordinary prose does not — it is a claim
about a state that recedes with every commit, and nothing will ever flag it stale because it
was never true of the present. Git already records the change losslessly.

The class is drawn at **self**-reference, deliberately and narrowly. "Previously the pipeline
was configured in Bash, it is now PHP" is the entire job of an upgrade guide and must never be
reported. The full class statement, its bounds, the two independent searches and the exclusion
rationale are in [clause-3.1-record.md](clause-3.1-record.md).

## Goals

- A detector for the class exists, is always-on, and runs from `bin/qa` as lane `docsProse`
  under the stable identifier `phpqaci.docsProse`.
- The detector is proven red against the real repository *before* any instance is fixed, and
  that red run survives as its own commit.
- Every instance in the corpus is found by two independent search techniques, counted, then
  fixed.
- The identifier resolves to a page stating the correct construction, not merely the
  forbidden one.

## Non-Goals

- Reporting prose about the **subject's** past. Upgrade guides, deprecation notices and a
  rule's motivation section are all made of such sentences and none carries the hazard.
- Policing plan folders, `JOURNAL/` day-files or `remote-docs/`. Those are records of a
  moment by design, and a vendored document is not ours to rewrite.
- Natural-language understanding. The detector matches a closed set of self-reference shapes;
  it is a net, not a judge, and the two-technique sweep is what covers its gaps.

## Tasks

### Phase 1: Record the class (clause 3.1)

- [x] ✅ **Task 1.1**: State the class, the hazard and the self-reference boundary in
  [clause-3.1-record.md](clause-3.1-record.md), with an exclusions table.

### Phase 2: Build the defence (clause 3.2)

- [x] ✅ **Task 2.1**: `DocumentSelfReferenceScanner` — a paragraph-flattening matcher, so a
  phrase straddling a hard-wrapped newline is still found.
- [x] ✅ **Task 2.2**: Wire it as its own lane rather than folding it into `markdownLinks`: a
  lane prints one identifier, and an identifier must resolve to documentation about the
  rule that fired (method specification clause 3.6).
- [x] ✅ **Task 2.3**: Register in `ToolRegistry` and `ShippedTools`; update the registry
  characterisation golden sets, which pin the `-t` API deliberately.

### Phase 3: Prove it red (clause 3.3)

- [x] ✅ **Task 3.1**: Run the lane against this repository and record the count.
- [x] ✅ **Task 3.2**: Commit the detector with the originating instances still present, so
  the red run is individually reachable in history (24e2115).

### Phase 4: Sweep and fix (clause 3.4)

- [x] ✅ **Task 4.1**: Technique 1 — pattern search over the corpus.
- [x] ✅ **Task 4.2**: Technique 2 — four sub-agents reading every document in full and
  judging prose, with no marker grepping, so the search does not inherit the detector's
  blind spots.
- [x] ✅ **Task 4.3**: Fix all 3 instances, in a commit separate from the detector's (d555f24).
- [x] ✅ **Task 4.4**: Re-run the lane and confirm green.

### Phase 5: Enforce (clause 3.5)

- [x] ✅ **Task 5.1**: Demonstrate the rule reported through `bin/qa` with the full battery
  green, per [CLAUDE/prepush-verification.md](../../prepush-verification.md).

### Phase 6: Document the identifier (clause 3.6)

- [x] ✅ **Task 6.1**: [docs/tools/docsProse.md](../../../docs/tools/docsProse.md) — states
  the correct construction with a before/after table, not only the forbidden one.
- [x] ✅ **Task 6.2**: Add `docsProse` to `docs/pipeline.md`, `docs/phpqa-tools.md`,
  `docs/phpstan-rules/README.md` and `CLAUDE.md`. `README.md` needs no entry: it points at
  `ToolRegistry` rather than carrying a lane list, which is the construction Task 4.3
  restored.

## Success Criteria

- [x] `vendor/bin/qa -t dp` exits 0 against this repository.
- [x] The red commit and the fix commit are separate, in that order (24e2115 then d555f24).
- [x] `vendor/bin/rule-doc phpqaci.docsProse` resolves to the remediation page.
- [x] The detector reports zero instances in `docs/upgrading-to-8.5.md`, the corpus's densest
  concentration of legitimate past-tense prose.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00011-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Red proven: 3 instances (`README.md` ×2, `docs/tools/phpstan.md` ×1), matching the union of
  both independent searches.
