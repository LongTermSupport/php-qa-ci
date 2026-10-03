# Plan 00016: method 1.1.0 and the deferred-defect record

**Status**: Not Started
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

- [ ] ⬜ **Task 1.1**: Decide its form (a typed file under `qaConfig/`, its fields: the defect, the
  class where apparent, where it was found, and who decided to defer it), and record the decision
  with its reasoning in this plan. Prefer a format the toolchain already parses.
- [ ] ⬜ **Task 1.2**: Red first, then the reader, validation (a malformed entry fails rather than
  vanishing), and the `bin/rules` text and JSON listing.
- [ ] ⬜ **Task 1.3**: The agent summary (`ActiveDefencesSummary`) carries the deferred entries, or a
  count and the record's path when there are many.

### Phase 2: Declaration and documentation

- [ ] ⬜ **Task 2.1**: Refresh `remote-docs/defence-before-fix.github.io/` (`.claude/hooks-daemon/bin/hooks-daemon remote-docs refresh --all`) and move the declaration to method 1.1.0 at both levels;
  `DefenceBeforeFixDeclarationTest` reads the versions from the vendored files.
- [ ] ⬜ **Task 2.2**: The defaults page, `CLAUDE/DefenceBeforeFix.md` and the CLAUDE.md block
  template name the record.
- [ ] ⬜ **Task 2.3**: This repository's deferred defects, if any remain open, go into the record.

## Success Criteria

- [ ] `bin/rules .` lists the deferred-defect record, and a fixture entry appears in its JSON.
- [ ] `composer.json` declares method 1.1.0 at both levels, matching the vendored specification.
- [ ] The full battery passes.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00016-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan filed
