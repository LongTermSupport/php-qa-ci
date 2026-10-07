# Plan 00018 decisions

## Decision 1: the engine is the daemon's own formatter (option c)

The Owner's request was for markdown php-qa-ci or a consumer writes to stop being reformatted
back and forth by the hooks daemon, using the daemon's rules rather than fighting them. Choosing
the engine was delegated: pick it if one option clearly wins. One does.

### The options

- **(a) A PHP port of mdformat's renderer** on a CommonMark/GFM parser such as
  `league/commonmark`. Pure PHP, so it fits the "PHP is for logic" ruling and runs anywhere.
  But its output only has to be *close* to mdformat's for the fight to come back: one escaping or
  list-numbering difference and the daemon rewrites the file again. The PHP parsers do not share
  `markdown-it-py`'s AST, so agreement is a long tail of special cases, chased against an
  upstream that moves (the daemon pins only `mdformat>=0.7`, `mdformat-gfm>=0.4`, so its resolved
  version changes when its venv is rebuilt). This is the most code, it never reaches "the same
  by construction", and the differential corpus would fail every time mdformat changes.
- **(b) mdformat itself, from a pinned Python environment php-qa-ci provisions.** Exact output
  for the pinned version, but the daemon does not use the pinned version: it uses whatever its
  own venv resolved. Matching means reading the daemon's resolved version, and when the daemon
  is there that is strictly worse than (c). It also adds a Python runtime and a venv to a PHP
  toolchain's install, in every consumer and in CI.
- **(c) Delegate to the daemon's `format-markdown`** (`<path>`, recursive over a directory,
  `--check` for a dry run that exits 1 when a file would change). The output is the daemon's
  because it is the daemon's code, at the daemon's resolved version, so agreement holds by
  construction and needs no corpus to keep it true.

### Why (c) wins

The fight only exists where the daemon is installed: without the daemon nothing rewrites the
markdown, so there is nothing to agree with. Option (c) covers exactly the case the problem
exists in, with zero drift and the least code. Options (a) and (b) spend their cost on
installs without the daemon, which is the case that has no problem to solve.

### What (c) does not give, and how that is covered

- **CI has no daemon.** The lane skips there with a one-line notice, not a pass. A formatting
  drift therefore surfaces at the next local run, or at the next daemon restart, which commits
  the reformatted `CLAUDE.md` itself. Nothing reaches a consumer unformatted that a consumer's
  own daemon would not fix on its first restart.
- **Generated markdown is checked byte for byte in CI** (`AgentContextIsCurrentTest`), so a
  generator cannot route through the daemon at test time. The generated active-defences region
  stays emitted in canonical form by `ActiveDefencesSummary` (Plan 00017 Task 2.1). Its shape
  is one fixed bullet list, so that is a short, stable rule, not a renderer. Plan Task 2.3
  narrows accordingly: no generator calls the daemon; the lane, run locally with the daemon
  present, proves the generated region is already canonical.
- **A consumer without the daemon gets no markdown formatting** from this lane. That is a
  different goal from the Owner's (no fight); if it is wanted later, it is option (b), not (a).

### Consequence for the plan

- Task 1.2's differential corpus is not needed: agreement is by construction. Edge fixtures stay
  useful as lane tests (the lane must not corrupt them), not as a parity proof.
- Phase 2 becomes the daemon locator and invocation, red first; Phase 3 is unchanged.
