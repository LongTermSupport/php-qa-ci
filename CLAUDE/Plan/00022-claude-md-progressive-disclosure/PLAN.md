# Plan 00022: CLAUDE.md progressive disclosure

**Status**: In Progress
**Created**: 2026-10-19
**Owner**: dev
**Priority**: High

## Overview

Root `CLAUDE.md` loads at launch, stays resident for the whole session, and costs about 42.6k
tokens (chars ÷ 4). Two generated regions, which this plan does not edit, account for about 25k
of that: the hooks-daemon `<hooksdaemon>` block (about 22.6k) and the `bin/rules`
active-defences region (about 2.5k). The remaining 993 lines (about 17.5k tokens) are
hand-written. Most of them paraphrase or copy pages under `docs/`, they hold several stale
facts, and the canonical text of five Owner rulings exists only here: knowledge & memory,
release lines, dogfooding, "Bash is for invocation, PHP is for logic" and "the pipeline is not a
tool proxy". Partial restatements exist elsewhere (the release-line policy in
`README.md#branches-and-versions` and `.github/ISSUE_TEMPLATE/bug_report.yml`; the memory
ruling in a comment in `.claude/hooks-daemon.yaml`), and the per-fact check covers them. Measured
inventory: [subagent-reports/261019-claude-md-inventory-opus.md](subagent-reports/261019-claude-md-inventory-opus.md).
Independent review, whose required changes this plan incorporates:
[subagent-reports/261019-plan-review-opus.md](subagent-reports/261019-plan-review-opus.md).

The plan cuts the hand-written part to a resident **binding floor** (every Owner ruling,
condensed, linking its canon) plus a **navigation map**. Agent-only depth moves into
`CLAUDE/*.md`; consumer-visible behaviour stays canonical in `docs/`; duplicates are retired only
after each of their facts is shown to exist at its destination. Directory-shaped triggers get
routing-table nested `CLAUDE.md` files, and file-shaped triggers get `.claude/rules/` path rules.
Path rules are expected not to load when php-qa-ci is worked on from a consumer's `vendor/`
(Task 1.2 (b) verifies this), so they are accelerators only and never the only route.

This is a consumer win as well. A consumer agent that reads any file under
`vendor/lts/php-qa-ci/` lazily loads this repository's `CLAUDE.md`, so the vendored copy shrinks
with it.

The method follows the owner-supplied
[docs-optimisation prompt](https://gist.github.com/edmondscommerce/a5921ecb5b096439a6e505716e3a6a0d),
adapted to the hooks-daemon documentation strategy
([CLAUDE/core/DocumentationStrategy.core.md](../../core/DocumentationStrategy.core.md)). Where
the two differ, the daemon's rules win, except for Decision 1, a recorded project deviation from
core R2/R3.

## Decisions

Full text, the verified loading mechanics and the repository constraints (R4a marker placement,
R5, R7a, R7d, DBF §3.2, daemon auto-commit) are in [DECISIONS.md](DECISIONS.md).

1. **SSoT direction**: `docs/` stays canonical for consumer-visible behaviour, `CLAUDE/` for
   agent-only material; a declared deviation from core R2/R3, stated at the point of use by a
   `phpqaci-reference-docs.md` rule.
2. **Retire, never copy**: a duplicate section goes only after a per-fact check, in the same
   commit that lands its unique facts.
3. **Every ruling has a canon**: knowledge & memory canonical in root; new
   `CLAUDE/release-lines.md`, `CLAUDE/dogfooding.md`, `CLAUDE/architecture.md`; consumer half of
   "not a tool proxy" in `docs/configuration.md`.
4. **No new skills**: descriptions are resident here and in every consumer.
5. **Stale-roster class removed at source** (DBF, R5): no hand roster in root; `docs/pipeline.md`
   points at `bin/qa -h`, the identifier index and `ToolGateEnum`; `docs/phpqa-tools.md` goes to
   an issue.
6. **Auto memory off**: `autoMemoryEnabled: false` in `.claude/settings.json` (absent today).
7. **One trigger per subtree**: routing-table nested `CLAUDE.md` for `src/Pipeline/`,
   `src/PHPStan/`, `scripts/`; path rules for file-shaped triggers; none under `docs/`.

## Goals

- The hand-written part of root `CLAUDE.md` drops from about 17.5k tokens to about 2.2k, hard
  ceiling 3k, at most about 100 lines.
- Whole file: from about 42.6k to about 27k raw tokens (−36%), measured with `/context` as well
  as chars ÷ 4. Afterwards the `<hooksdaemon>` block is more than 80% of the resident file, which
  Task 6.6 takes upstream.
- Both generated regions stay byte-identical to their generators.
- Every Owner ruling stays resident in the floor, linking its canon, with every `FLOOR` clause
  intact.
- Every fact in the current hand-written text survives at a traceable destination.
- No new duplicate blocks; the `CLAUDE.md:523-534` ≡ `docs/pipeline.md:29-40` duplicate is gone.
- Every link and anchor that pointed into the moved sections resolves after the change.

## Non-Goals

- Editing the `<hooksdaemon>` block locally (Task 6.6 reports it upstream instead).
- Moving the active-defences region.
- Rewriting `docs/` beyond the facts that move into it and Decision 5.
- Changing consumer-deployed templates (`templates/root-CLAUDE-phpqaci-block.md.template`,
  `scripts/lib/deploy-claude-block.inc.bash`).
- Adding `.claude/rules` to `withMarkdownFormatPaths()`: those files are formatted on
  Write/Edit, and the daemon's own rules there would show as deployed-artefact drift.

## Tasks

### Phase 1: Baseline and live verification

- [x] ✅ **Task 1.1**: Journal the baseline: `CLAUDE.md` byte, line and token split, the real
  resident size from `/context` (or the token-count API), `git rev-parse HEAD`, and the
  `docs-qa --sweep --json` findings for `CLAUDE.md`.

- [ ] 🔄 **Task 1.2** ((a) and (b) evidenced by the coordinator's live session, see the journal;
  (c) blocked: a spawned `claude -p` is not logged in): Live-test the mechanics with headless `claude -p` sessions and a scratch
  `settings.json` that registers an `InstructionsLoaded` hook logging to `untracked/scratch/`:

  - (a) in this worktree: a sentinel `paths:` rule loads on a matching Read, and only then; a
    plain link does not load its target;
  - (b) in a scratch consumer under `untracked/scratch/consumer/`, whose
    `vendor/lts/php-qa-ci` is a git worktree of this branch: reading
    `vendor/lts/php-qa-ci/src/Pipeline/<file>.php` loads php-qa-ci's root `CLAUDE.md` and a
    sentinel `src/Pipeline/CLAUDE.md`, but not php-qa-ci's `.claude/rules`;
  - (c) the `/context` figure for the baseline file.

  Journal each result. A result that contradicts "Verified loading mechanics" stops the plan for
  revision.

### Phase 2: Recorded decisions

- [x] ✅ **Task 2.1**: In `CLAUDE/DocumentationStrategy.md` under project-specific additions,
  record: Decision 1 as a deviation from core R2/R3 with its justification; the R4a marker and
  its placement; Decision 7's split between nested files and path rules; and whether
  `documentation.qa.generated_docs` can declare the active-defences **region**. If it cannot,
  record the accepted R10 advisory.
- [x] ✅ **Task 2.2**: Add `.claude/rules/phpqaci-reference-docs.md` (R7a shape) stating the
  Decision 1 exception and linking `CLAUDE/DocumentationStrategy.md`, before any `docs/` edit.
- [x] ✅ **Task 2.3**: Journal the Defence Before Fix disposition of the stale-roster class
  (Decision 5): class attributed, option (a) excluded by §3.2, option (c) applied, instances
  listed with their sweep (`CLAUDE.md`, `docs/pipeline.md`, `docs/phpqa-tools.md`).

### Phase 3: Relocation, one atomic commit per section

Each commit adds the destination text, removes the section from `CLAUDE.md`, and repoints every
coupling into that section, so no commit leaves a broken anchor or a dangling procedure step.
Stale facts are corrected as the text lands.

- [x] ✅ **Task 3.1**: `CLAUDE/architecture.md` takes core components, the run walk-through, the
  tool-runner invariants, "Bash is for invocation" with its ShellCheck example, and the
  contributor half of "not a tool proxy". Same commit: repoint `docs/tools/shellCheck.md:33` and
  `src/PHPStan/Rules/RequireDeclareStrictTypesRule.php:18`.
- [x] ✅ **Task 3.2**: `CLAUDE/dogfooding.md` from the dogfooding section. Same commit: repoint
  the docblock in `src/PHPStan/Rules/ForbidUnanchoredVendorSubstringCheckRule.php:28`.
- [x] ✅ **Task 3.3**: `CLAUDE/release-lines.md` from the release-line section. Per-fact check
  against `README.md#branches-and-versions` and `.github/ISSUE_TEMPLATE/bug_report.yml`: the new
  file carries only what those do not (the #36 exception, "not backported proactively", the
  issue-states-its-line trigger, the Owner-decision framing) and links README for the rest
  (Decision 2). Same commit: repoint the comments in `tests/Small/IssueFormsTest.php:16,42`.
- [x] ✅ **Task 3.4**: `CLAUDE/claude-code-integration.md` for the Claude Code hooks deploy and
  the daemon full-QA blocker pointer, linking `.claude/hooks/README.md` rather than repeating it.
  Same commit: repoint `.claude/hooks/README.md:419`.
- [x] ✅ **Task 3.5**: Pipeline order, preflight, configuration, platform, PHP 8.5 notes,
  built-in hooks, overrides, requirements, customisation, the Tools Reference and the consumer
  half of "not a tool proxy" (to `docs/configuration.md`): per-fact check against the
  inventory's duplication map; each CLAUDE.md-only fact goes to its `docs/` canon or, if
  agent-only, to `CLAUDE/architecture.md`; Decision 5 is applied to `docs/pipeline.md`. Commit
  per section; the Preflight removal carries the `docs/tools/phpstan.md:99` repoint (to
  `docs/pipeline.md#2-preparation`), and the phase-list and Tools Reference removal carries the
  rewritten new-tool step in `CLAUDE/tool-boundaries.md:70-72`. Keep `FACT-MAP.md` in this plan
  folder: one row per paragraph or bullet with a stable fact ID (section → destination → line),
  binding clauses marked `FLOOR`, seeded from [FLOOR-CLAUSES.md](FLOOR-CLAUSES.md). The
  Read-Only/CI removal carries a new sentence in `CLAUDE/prepush-verification.md`: `CI` controls
  interactivity only, read-only is orthogonal to it, and `CI=true` alone does not make a run
  read-only (F13's canon does not say this today). The memory floor bullet stays the canon;
  the comment in `.claude/hooks-daemon.yaml` is checked against it and left as a pointer.

### Phase 4: The new root CLAUDE.md

- [x] ✅ **Task 4.1**: Rewrite the remaining hand-written part as a short orientation, the
  binding floor (one bullet per Owner ruling, 1–4 lines, ending in its canonical link, under the
  single block-level marker), and the navigation map (task → document, plain links, no `@`).
  Every `FLOOR` row stays resident. No `## Overview`/`## Summary` headings, ✅ + status words or
  ISO dates.
- [x] ✅ **Task 4.2**: Generated regions. The `<hooksdaemon>` block is not regenerated in the
  worktree; it is kept byte-identical to the target branch's block, verified by extracting the
  span and diffing it against `git show origin/php8.5:CLAUDE.md` after `php8.5` is merged in
  (Delivery); on a merge conflict, php8.5's block is taken verbatim. Before committing, run the
  daemon's formatter over `CLAUDE.md` (`bin/qa -t mdf`, or the daemon CLI's `format-markdown`),
  so a restart finds nothing to reflow. Then run
  `php bin/rules . --write-agent-summary=CLAUDE.md`, re-run the formatter, and confirm
  `AgentContextIsCurrentTest` passes.

### Phase 5: Accelerators

- [ ] ⬜ **Task 5.1**: Nested routing files per Decision 7 (`src/Pipeline/CLAUDE.md`,
  `src/PHPStan/CLAUDE.md`, `scripts/CLAUDE.md`), each at most 15 lines of pointers.
- [ ] ⬜ **Task 5.2**: Path rules (prefix `phpqaci-`, R7a shape) for the file-shaped triggers in
  Decision 7, with the inventory's validated globs minus any subtree a nested file now covers.
  `**/*.sh` is dropped: every tracked `.sh` file is daemon-owned under `.claude/`.
- [x] ✅ **Task 5.3**: Set `autoMemoryEnabled: false` in `.claude/settings.json`.

### Phase 6: Verification, review and delivery

- [ ] ⬜ **Task 6.1**: Deterministic checks, with named instruments. The daemon CLI lives in the
  main checkout (`/workspace/.claude/hooks-daemon/bin/hooks-daemon`); run it with
  `--project-root` set to this worktree's absolute path (a relative one makes `--lint` fail):
  - `docs-qa --sweep --json`: no `pointer-resolves`, `duplicate-block`, `rules-file-shape` or
    `module-doc-budget` finding involving a file this plan touched;
  - `docs-qa --lint <file>` on every new rule file and nested `CLAUDE.md`;
  - `bin/mdlinks` for the `docs/` edits (it covers `README.md` and `docs/` only);
  - `AgentContextIsCurrentTest`;
  - the `@`-import audit (no `^\s*@` or `[@` outside fences in any loaded file);
  - every `FACT-MAP.md` row resolves to a present line;
  - token figures from `/context` and chars ÷ 4, journalled.
- [ ] ⬜ **Task 6.2**: Live trigger test: touching one file per path rule injects that rule
  (including an extensionless `bin/` stub), and touching a file under each nested-file subtree
  loads that file, here and from the scratch consumer of Task 1.2.
- [ ] ⬜ **Task 6.3**: Surfacing check: five headless scenario prompts from a fresh session, each
  recording which canonical doc the agent opened before acting: add a new lane; fix a php-qa-ci
  bug from a consumer's `vendor/`; prepare a release entry; a test exited 139; tune a consumer's
  `qa.php`. A miss is a navigation-map or trigger defect, fixed before merge.
- [ ] ⬜ **Task 6.4**: A fresh, sceptical reviewer re-audits from the files alone: the import
  audit, knowledge preservation against `git show <baseline>:CLAUDE.md`, every `FLOOR` row
  against the new `CLAUDE.md`, the SSoT split, the globs and nested files, and regressions this
  work introduced. Every major finding is fixed.
- [ ] ⬜ **Task 6.5**: Changelog. Each commit touching a watched path (`qaConfig/qa.php`
  `withChangelogWatchedPaths`, which includes `src/`, `bin/` and `scripts/`) carries a
  `## Unreleased` entry or a `Changelog: none — <reason>` trailer: the two `src/` docblocks and
  the nested files under `src/` and `scripts/`. Add a `Changed` entry for the slimmer vendored
  `CLAUDE.md`, since consumer agents that read vendored files load it.
- [ ] ⬜ **Task 6.6**: Upstream and follow-ups:
  - an upstream report via the daemon CLI's `issue-report`: mdformat column padding inflates
    the injected rules section (about 83.7k chars raw, of which the table rows are 80.5k,
    against about 36k squeezed), asking for a
    compact rendering; and a request for an option that marks a human-tree subtree as canonical
    reference documentation (Decision 1);
  - a journal note for the Owner listing the handlers whose rows cost the most;
  - a php-qa-ci issue to retire `docs/phpqa-tools.md` as a parallel hand roster (it still calls
    Yaml Lint a platform lane);
  - a php-qa-ci issue on the vendored `CLAUDE.md` carrying this repository's `<hooksdaemon>`
    block into consumer sessions.
- [ ] ⬜ **Task 6.7**: Pre-push gate (`CLAUDE/prepush-verification.md`), open the pull request,
  PR verification (`CLAUDE/pr-verification.md`), merge.

## Success Criteria

- [ ] The hand-written part of `CLAUDE.md` is ≤ 3k tokens (target about 2.2k) and ≤ about 100
  lines; whole-file before/after from `/context` is journalled. Both generated regions are
  unchanged in content.
- [ ] Every Owner ruling is resident under the binding-floor marker, linking an existing canon,
  and every `FLOOR` row of `FACT-MAP.md` is present.
- [ ] `FACT-MAP.md` accounts for every fact in the baseline hand-written text.
- [ ] Every Task 6.1 instrument is clean.
- [ ] Every path rule and nested file is shown to load on a matching file, live, and every
  Task 6.3 scenario reached its canonical doc.
- [ ] The independent reviewer reports no unresolved major findings, and the pre-push gate is
  green.

## Review disposition

All required changes R1–R13 are applied (R1: Overview, Decision 1, Tasks 2.1–2.2, 6.6; R2:
Decision 7, Tasks 5.1–5.2; R3: Task 1.2; R4: Phase 3; R5: `FLOOR-CLAUSES.md`, Tasks 3.5, 4.1,
6.4; R6: Task 4.2; R7: Delivery; R8: Task 6.5; R9: Goals, Task 6.6; R10: Decision 5, Task 2.3;
R11: DECISIONS.md constraints, Task 4.1; R12: Task 6.1; R13: Task 6.3). Optional O2, O3, O4, O5, O6 and O7
are adopted. Declined:

- **O1** (`RootClaudeMdBudgetTest`): DBF §3.2 rules out a test as the Detector; the line and
  token ceilings are Success Criteria, Claude Code warns on over-long instruction files, and a
  Detector is built if re-growth is actually seen.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00022-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan drafted; inventory and mechanics reports in `subagent-reports/`.
- Plan revised for the independent review (APPROVE-WITH-CHANGES).
- **Branch dependency**: this branch stacks on `chore/hooks-daemon-v3.70.0`, which is not yet on
  `php8.5`. The pull request opens only after that one merges and `php8.5` has been merged into
  this branch.
