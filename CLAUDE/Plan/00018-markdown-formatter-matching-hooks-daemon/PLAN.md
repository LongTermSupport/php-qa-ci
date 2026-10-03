# Plan 00018: markdown formatter matching hooks daemon

**Status**: In Progress
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

- A lane that formats markdown with the daemon's own transform: reports (read-only) or applies (writable), like Rector and PHP CS Fixer
- Skips with a notice, never passes silently, where the daemon is absent
- php-qa-ci's generated markdown is already in the daemon's form, so the lane never changes it

## Non-Goals

- Changing the daemon's formatter; a disagreement found upstream is an upstream issue
- Formatting anything the daemon does not format (non-`.md` files)

## Tasks

### Phase 1: decide the engine (Owner decision)

- [x] ✅ **Task 1.1**: Engine decided: delegate to the daemon's own `format-markdown` (option c); options, reasoning and limits in [DECISIONS.md](DECISIONS.md) Decision 1
- [x] 🚫 **Task 1.2**: Differential corpus: not needed, agreement is by construction (Decision 1)

### Phase 2: the daemon formatter adapter (Defence Before Fix: red first)

- [x] ✅ **Task 2.1**: Red `2c4f183`: the daemon locator (project, or above it up to the work-tree root) and the lane (`format-markdown`, `--check` read-only, skip without the daemon); the edge fixtures are dropped, since the lane formats nothing itself (Decision 2)
- [x] ✅ **Task 2.2**: `HooksDaemonCliLocator`; no `bin/` entry point, since the daemon's CLI is already the standalone command (Decision 2)
- [x] ✅ **Task 2.3**: The lane over `CLAUDE.md` changes nothing: the generated region is already canonical; no generator calls the daemon

### Phase 3: the lane

- [x] ✅ **Task 3.1**: `markdownFormat` (`-t mdf`), coding-standards phase, `withMarkdownFormatPaths()` defaulting to `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/`, `CLAUDE/`; identifier, index row and page; tool-boundary record in Decision 2
- [x] ✅ **Task 3.2**: `docs/tools/markdownFormat.md`, `CLAUDE.md`, `docs/pipeline.md`, `docs/upgrading-to-8.5.md` and the CHANGELOG entry
- [ ] ⬜ **Task 3.3**: Both battery runs pass, then a daemon restart changes no tracked markdown file

## Success Criteria

- [x] The lane's output is the daemon's (it runs the daemon's code)
- [ ] A daemon restart after a full `bin/qa` run changes no tracked markdown file
- [x] Without the daemon the lane skips with a notice naming why

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00018-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- <!-- milestone or delivery commit hash -->
