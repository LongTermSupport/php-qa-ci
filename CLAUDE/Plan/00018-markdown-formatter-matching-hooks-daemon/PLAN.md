# Plan 00018: markdown formatter matching hooks daemon

**Status**: Not Started
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: Medium

## Overview

The hooks daemon reformats markdown with one shared transform
(`utils/markdown_format.py`: mdformat with the `gfm` extension, `number: true`, thematic breaks
restored from 70 underscores to `---`, YAML front matter split off and re-attached byte for
byte). It runs after every Write/Edit of a `.md` file, on `format-markdown`, and over the whole
of `CLAUDE.md` whenever the daemon restarts, auto-committing the result.

php-qa-ci writes markdown too, most visibly the generated active-defences region of `CLAUDE.md`
(`bin/rules --write-agent-summary`), which `AgentContextIsCurrentTest` compares byte for byte.
Plan 00017 Task 2.1 made that one generator emit mdformat's canonical form by hand. That fixes
one generator, not the class: any markdown php-qa-ci writes, and any a consumer writes by hand,
can be rewritten by the daemon and fight whatever wrote it. The Owner's ruling is to roll with
the daemon's format rather than fight it.

This plan gives php-qa-ci its own markdown formatter whose output is the daemon's, byte for byte,
so nothing php-qa-ci or a consumer produces is reformatted back and forth, and the generators stop
hand-coding canonical form.

## Goals

- One formatter in php-qa-ci, producing exactly what the daemon's transform produces for the same input
- Every markdown generator in php-qa-ci routes its output through it (no hand-coded canonical form)
- A lane that reports (read-only) or applies (writable) formatting, like Rector and PHP CS Fixer
- A differential test proving agreement with the daemon's transform over a corpus, run when the daemon is present

## Non-Goals

- Changing the daemon's formatter; a disagreement found upstream is an upstream issue
- Formatting anything the daemon does not format (non-`.md` files)

## Tasks

### Phase 1: decide the engine (Owner decision)

- [ ] ⬜ **Task 1.1**: Record the options and their cost in `DECISIONS.md` and get the Owner's ruling:
  (a) a PHP port of mdformat's rendering on a CommonMark/GFM parser, held to mdformat by the differential corpus;
  (b) run mdformat itself at the daemon's resolved version, from a pinned, isolated Python environment the lane provisions;
  (c) delegate to the daemon's own `format-markdown` when the daemon is installed, and skip or fail otherwise.
  Byte agreement depends on the mdformat version, and the daemon pins only `mdformat>=0.7`, `mdformat-gfm>=0.4`, so each option states how it tracks the daemon's resolved version
- [ ] ⬜ **Task 1.2**: Build the differential corpus: every `.md` this repository tracks, plus edge fixtures (tables with escaped pipes, nested lists, numbered lists, front matter, thematic breaks, backslash escapes such as `\S`, HTML comments as region markers)

### Phase 2: the formatter (Defence Before Fix: red first)

- [ ] ⬜ **Task 2.1**: Red: the differential test, failing until the formatter agrees on the whole corpus
- [ ] ⬜ **Task 2.2**: The formatter, per the Task 1.1 ruling
- [ ] ⬜ **Task 2.3**: `ActiveDefencesSummary` and every other markdown writer emit through it; the hand-coded canonical form from Plan 00017 Task 2.1 goes

### Phase 3: the lane

- [ ] ⬜ **Task 3.1**: Red, then a `markdownFormat` lane over `README.md`, `docs/`, `CLAUDE.md` and `CLAUDE/`, writable applies and read-only fails with the pending diff; identifier, index row and page as every lane has
- [ ] ⬜ **Task 3.2**: Consumer documentation and CHANGELOG entry

## Success Criteria

- [ ] For every file in the corpus, php-qa-ci's formatter output equals the daemon transform's output
- [ ] A daemon restart after a full `bin/qa` run changes no tracked markdown file
- [ ] No php-qa-ci generator hand-codes canonical markdown

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00018-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- <!-- milestone or delivery commit hash -->
