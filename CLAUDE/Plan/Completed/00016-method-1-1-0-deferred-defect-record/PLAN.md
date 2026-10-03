# Plan 00016: method 1.1.0 and the deferred-defect record

**Status**: Complete (c2941a1, merged 00ce4e0)
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High

## Overview

Method specification 1.1.0 was published on 2026-10-02, and php-qa-ci declares and vendors 1.0.1.
Its new obligation is in section 2, "Deferring the fix is not a fourth": a practitioner who finds
a defect and does not fix it now MUST record it where the project's other decisions are
enumerable under clause 8.7, naming the class where one is apparent. The section's existing "no
pattern exists" sentence must be recorded in the same place, not only with the fix.

php-qa-ci gives a project no such place. `bin/rules` enumerates the defences and the `ignoreErrors`
record, and nothing else, so a deferred defect lives wherever the practitioner happened to put it:
this repository keeps them in GitHub issues, which the enumeration cannot reach and which do not
work offline. Moving the declaration to 1.1.0 without that place would be a claim the repository
does not meet.

## Goals

- A project has a deferred-defect record in its own repository, read by the toolchain, listed by
  `bin/rules` (text and JSON) beside the defences and the `ignoreErrors` record, and carried into
  the agent summary.
- `docs/defence-before-fix-defaults.md` names it as the default place for deferred defects and for
  the "no pattern exists" sentence.
- The vendored specifications are refreshed and the declaration states method 1.1.0 at both
  levels, with any gap 1.1.0 opens recorded against its clause.
- This repository's own deferred defects are in the record.

## Non-Goals

- Reading an issue tracker. The record works offline from the checkout, like the rest of the
  enumeration.
- Deciding what is deferred. Whether a defect stays unfixed is the Owner's decision; the record is
  what puts it in front of them.

## Tasks

### Phase 1: The record

- [x] ✅ **Task 1.1**: Decide its form (a typed file under `qaConfig/`, its fields: the defect, the
  class where apparent, where it was found, and who decided to defer it), and record the decision
  with its reasoning in this plan. Prefer a format the toolchain already parses. Decisions 1 and 2.
- [x] ✅ **Task 1.2**: Red first, then the reader, validation (a malformed entry fails rather than
  vanishing), and the `bin/rules` text and JSON listing.
- [x] ✅ **Task 1.3**: The agent summary (`ActiveDefencesSummary`) carries the deferred entries, or a
  count and the record's path when there are many (more than ten).

### Phase 2: Declaration and documentation

- [x] ✅ **Task 2.1**: Refresh `remote-docs/defence-before-fix.github.io/` (`.claude/hooks-daemon/bin/hooks-daemon remote-docs refresh --all --verbatim`) and move the declaration to method 1.1.0 at both levels;
  `DefenceBeforeFixDeclarationTest` reads the versions from the vendored files. Decision 3.
- [x] ✅ **Task 2.2**: The defaults page, `CLAUDE/DefenceBeforeFix.md` and the CLAUDE.md block
  template name the record.
- [x] ✅ **Task 2.3**: This repository's deferred defects, if any remain open, go into the record.
  Decision 4.

## Technical Decisions

### Decision 1: the record is `qaConfig/defect-record.neon`, two sections

NEON, because the toolchain already reads it (`nette/neon` is a runtime dependency, and the
`ignoreErrors` record it sits beside is NEON), it carries comments, and it needs no loader of its
own. `qaConfig/qa.php` was rejected: `bin/rules` would have to build the whole typed configuration
to read a list of sentences. JSON was rejected because it has no comments. The cost of NEON is
that prose containing `,` `:` `(` `[` `{` or `#` must be quoted; the reader names the line it could
not parse, and the documentation says so.

One file holds both things section 2 asks for: `deferred` (`defect`, `class` where apparent,
`found`, `deferredBy`) and `noPattern` (`defect`, `found`, `conclusion`, and `techniques`, a list
of at least two different entries, because the sentence must name at least two). An unknown
section or field is a problem rather than ignored: a misspelt `class` would otherwise drop out of
the enumeration unseen, which is the failure the record exists to prevent.

### Decision 2: an assertion in `phpstanIgnoreJustification`, not a new lane

The tool-boundaries test: (1) the question "is the project record usable" is already asked by
this lane, of `ignoreErrors`; (2) nobody would type a lane that only checks this file; (3) its help
line could not stand without naming the record. So the lane gained an assertion, its identifier is
unchanged (renaming it would break every consumer that types `-t pij`), its description and page
grew a section, and both halves always run. `bin/rules` refuses a record it cannot read, as it
refuses an include it cannot follow.

### Decision 3: 1.1.0 opens no gap at either level

The new obligation (section 2) is met by Decisions 1 and 2 at the artefact level and by this
repository's own record at the project level. The other 1.1.0 change, a conforming remediation's
verdict resting on reproduction (section 7), is about how a remediation is judged, not a
mechanism the toolchain supplies. The detector and toolchain specifications did not change. The
refresh needs `--verbatim`: without it the hooks daemon re-captured the specifications as
conversions, so `DefenceBeforeFixDeclarationTest` now also asserts each vendored copy is verbatim.

### Decision 4: this repository's own deferred defects

`gh issue list --state open`: #10, #36 and #37 are being fixed by work in flight; #13 and #18 are
enhancement requests, not defects. The record holds one entry: the hooks daemon's refresh dropping
the verbatim capture mode, found while doing this plan, whose code is not in this repository. Its
`deferredBy` says the decision is open: that is the Owner's.

## Success Criteria

- [x] `bin/rules .` lists the deferred-defect record, and a fixture entry appears in its JSON.
- [x] `composer.json` declares method 1.1.0 at both levels, matching the vendored specification.
- [x] The full battery passes: the writable and the read-only `bin/qa` both exit 0 on the
  merged branch.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00016-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan filed
- Red: a6ece89. Phases 1 and 2 implemented in c2941a1, merged into the sweep branch as 00ce4e0.
- Full battery green, writable and read-only, on `bugfix/known-defects-sweep`.
