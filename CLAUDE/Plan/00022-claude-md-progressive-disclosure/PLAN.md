# Plan 00022: CLAUDE.md progressive disclosure

**Status**: Not Started
**Created**: 2026-10-19
**Owner**: dev
**Priority**: High

## Overview

Root `CLAUDE.md` is loaded on every turn of every session and costs about 42.6k tokens. Two
generated regions, which this plan does not touch, account for about 25k of that: the
hooks-daemon `<hooksdaemon>` block (about 22.6k) and the `bin/rules` active-defences region
(about 2.5k). The remaining 993 lines (about 17.5k tokens) are hand-written. Most of them
paraphrase or copy pages under `docs/`, they hold several stale facts, and four Owner rulings
exist only here. Measured inventory:
[subagent-reports/261019-claude-md-inventory-opus.md](subagent-reports/261019-claude-md-inventory-opus.md).

The plan cuts the hand-written part to a resident **binding floor** (every Owner ruling,
condensed, each linking its canon) plus a **navigation map**. Agent-only depth moves into
`CLAUDE/*.md`, consumer-visible behaviour stays canonical in `docs/`, and duplicates are retired
only after each of their facts is shown to exist at its destination. Path-scoped rules under
`.claude/rules/` are added as accelerators. They are never the only route, because they do not
load when php-qa-ci is worked on from a consumer's `vendor/`.

The method follows the owner-supplied
[docs-optimisation prompt](https://gist.github.com/edmondscommerce/a5921ecb5b096439a6e505716e3a6a0d),
adapted to this repository's hooks-daemon documentation strategy
([CLAUDE/core/DocumentationStrategy.core.md](../../core/DocumentationStrategy.core.md)). Where
the two differ, the daemon's enforced rules win.

## Verified loading mechanics

Sources: the official Claude Code memory and plugin docs, as reported by
`untracked/agent-reports/auto/261019-161411-claude-code-guide-aa20e7ce64b15f1ad.md`. Task 1.2
re-checks them live in this repository.

- Root `CLAUDE.md` loads at launch and is re-read after `/compact`. Nested `CLAUDE.md` files
  load lazily, when a file in their subtree is read or edited.
- `@path` imports inline eagerly, up to four hops, and are skipped inside code spans and fences.
  This repository has none, and the plan adds none.
- `.claude/rules/*.md` with `paths:` load only when a matching file is touched. Without `paths:`
  they load always. `paths:` is the only frontmatter field Claude Code reads.
- Block-level HTML comments in `CLAUDE.md` are stripped before injection, so a marker comment
  costs nothing.
- Auto memory is switched off by `autoMemoryEnabled: false` (or
  `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`). This does not affect `CLAUDE.md` loading.
- Each skill's name and description are resident every turn; a new pointer skill is not free.

## Repository constraints (from the daemon's documentation strategy)

- **R7a**: a `.claude/rules/*.md` file is pointers only. It may hold at most two imperative
  lines and a link, with no tables, fences or procedures, and its body is capped at 15
  non-blank lines. A rule is never a destination for relocated content.
- **R4a**: a safety-critical rule may be restated at the point of use in compressed prose. The
  restatement cites its canon and carries a greppable marker. This plan chooses the marker
  `<!-- binding-floor: <canon path> -->` and records it in `CLAUDE/DocumentationStrategy.md`.
- **R5**: a derived roster (tool list, phase order) is stated only by its generator
  (`ToolRegistry`, which produces `bin/qa -h` and the active-defences region).
- The daemon writes the `<hooksdaemon>` block and auto-commits `CLAUDE.md` on a restart in the
  main checkout. Its injector skips linked worktrees, so all editing happens in this plan's
  worktree.
- The active-defences region stays in `CLAUDE.md`. Defence Before Fix TOOLING-SPEC 7.2 requires
  it in a file agents already load. `composer.json` declares no gap, and
  `AgentContextIsCurrentTest` and `update-deps.yml` hard-code the file.

## Decisions taken in this plan

1. **Single source of truth (SSoT) direction (inventory risk 1)**: `docs/tools/`,
   `docs/phpstan-rules/` and the consumer-behaviour pages under `docs/` stay canonical for
   anything a consumer can observe. They ship to consumers and `rule-doc` resolves to them.
   `CLAUDE/` owns agent-only material: architecture and its invariants, contributor procedures,
   and Owner rulings. This is recorded as a project addition in
   `CLAUDE/DocumentationStrategy.md`. The rejected alternative was to move the depth into
   `CLAUDE/` and thin `docs/`, which rewrites the shipped documentation for no benefit to an
   agent.
2. **Retire, never copy, what `docs/` already says.** A `CLAUDE.md` section that duplicates a
   `docs/` page is removed only after a per-fact check. Each fact found nowhere else (the
   inventory lists them per section) is first added to its canonical page in the same commit.
   Moving the text verbatim into `CLAUDE/` would create the duplicate-block findings this plan
   exists to remove.
3. **Rulings that live only in root keep a canon.** Knowledge & Memory and the release-line
   policy are short enough that the floor bullet is the canon, marked as such. Dogfooding, "Bash
   is for invocation, PHP is for logic" and "the pipeline is not a tool proxy" get canonical
   homes (`CLAUDE/dogfooding.md`, `CLAUDE/architecture.md`). The floor keeps a condensed line
   linking each.
4. **No new skills.** Every skill's description is resident on every turn. The intent triggers
   this repository needs already exist (`qa`, `defence-before-fix`, `branch-policy`,
   `phpstan-*`, `phpunit-*`). The navigation map plus path rules cover discovery. A skill is
   added later only for a procedure that is shown to be missed.
5. **Stale facts are fixed, not carried.** These are: the missing `deadCode` lane and its gate;
   the missing `qaConfig/pipeline.php` and `--agent-mode`; the broken phase numbering; the
   "project's vendor/bin is used" note; and "validation only". `docs/pipeline.md` and
   `docs/phpqa-tools.md` are fixed for the facts they share with the moved text.
6. **Auto memory is switched off in settings.** `autoMemoryEnabled: false` goes into
   `.claude/settings.json`, enforcing the binding memory policy at the harness level. The memory
   store is empty, so there is nothing to migrate.

## Goals

- The hand-written part of root `CLAUDE.md` drops from about 17.5k tokens to at most about 3k.
  Both generated regions stay byte-identical to their generators.
- Every Owner ruling stays resident as a marked binding-floor bullet that links its canon.
- Every fact in the current hand-written text survives, either in a `CLAUDE/*.md` home or in
  its `docs/` canon, with a traceable mapping.
- No new duplicate blocks. The existing `CLAUDE.md:523-534` ≡ `docs/pipeline.md:29-40`
  duplicate is gone.
- Every link and anchor that pointed into the moved sections resolves after the change.

## Non-Goals

- Shrinking the `<hooksdaemon>` block. It follows from the enabled handlers, and its column
  padding is daemon behaviour, to be raised upstream, not edited locally.
- Moving the active-defences region (a DBF TOOLING-SPEC 7.2 obligation).
- Rewriting `docs/` beyond the facts that move into it and the stale facts listed above.
  Retiring `docs/phpqa-tools.md` as a parallel index is recorded as a follow-up.
- Changing consumer-deployed templates (`templates/root-CLAUDE-phpqaci-block.md.template`,
  `scripts/lib/deploy-claude-block.inc.bash`).

## Tasks

### Phase 1: Baseline and live verification

- [ ] ⬜ **Task 1.1**: Record the baseline in the journal: the `CLAUDE.md` byte, line and token
  split, `git rev-parse HEAD`, and the docs-qa sweep findings for `CLAUDE.md`.

- [ ] ⬜ **Task 1.2**: Live-test the mechanics in this repository. In a fresh session:

  - confirm a sentinel in a new `paths:` rule loads when a matching file is touched, and only
    then (using `InstructionsLoaded` or `/context`);
  - confirm that a plain link does not load its target;
  - confirm `.claude/rules/` is not loaded when the repository is opened as
    `vendor/lts/php-qa-ci` inside a consumer.

  Record each result in the journal. If any result contradicts "Verified loading mechanics",
  stop and revise the plan.

### Phase 2: Canonical homes (agent tree)

- [ ] ⬜ **Task 2.1**: Create `CLAUDE/architecture.md`. It takes: core components and the
  run walk-through; the tool-runner invariants ("retry, aggregate and exit-code policies belong
  to the runner"); the "Bash is for invocation, PHP is for logic" ruling with its ShellCheck
  worked example; and the "not a tool proxy" ruling. Carry these verbatim, then correct the
  stale facts.

- [ ] ⬜ **Task 2.2**: Create `CLAUDE/dogfooding.md` from the dogfooding section, verbatim.

- [ ] ⬜ **Task 2.3**: Create `CLAUDE/claude-code-integration.md` for the Claude Code hooks
  deploy and the daemon full-QA blocker pointer. Where `.claude/hooks/README.md` already says
  the same thing, link to it instead of repeating it.

- [ ] ⬜ **Task 2.4**: For the pipeline order, preflight, configuration, platform, the PHP 8.5
  notes, built-in hooks, overrides, requirements, customisation and the Tools Reference:

  - run the per-fact check against the inventory's duplication map;
  - add each fact found only in `CLAUDE.md` to its `docs/` canon (`docs/pipeline.md`,
    `docs/configuration.md`, `docs/tools/<name>.md`, ...) or, if it is agent-only, to
    `CLAUDE/architecture.md`;
  - fix the stale facts in those pages.

  Keep a mapping table, one row per fact (section → destination → line), as a supporting
  document `FACT-MAP.md` in this plan folder.

- [ ] ⬜ **Task 2.5**: Record the marker spelling (R4a) and Decision 1's SSoT direction in
  `CLAUDE/DocumentationStrategy.md` under its project-specific additions.

### Phase 3: The new root CLAUDE.md

- [ ] ⬜ **Task 3.1**: Rewrite the hand-written part of `CLAUDE.md` as:

  - a short orientation (what php-qa-ci is; agent docs in `CLAUDE/`, human docs in `docs/`);
  - the binding floor: one marked bullet per Owner ruling, 1–4 lines each, ending in its
    canonical link (knowledge & memory, Defence Before Fix, segfault halt, releases from the
    changelog, tools stay current, issue loop, release lines, pre-push gate and PR
    verification, Bash vs PHP, not a tool proxy, CI ≠ read-only, dogfooding);
  - the navigation map: task → document, plain links only, no `@`.

  Avoid the daemon's banned instruction shapes (`## Overview`/`## Summary` headings, ✅ + status
  words, ISO dates).

- [ ] ⬜ **Task 3.2**: Regenerate and verify both generated regions:

  - the active-defences region with `php bin/rules . --write-agent-summary=CLAUDE.md`, then
    `AgentContextIsCurrentTest`;
  - the `<hooksdaemon>` block, checked byte-identical against a fresh daemon regeneration.

  Any formatter reflow of either region is reverted.

### Phase 4: Couplings and accelerators

- [ ] ⬜ **Task 4.1**: Repoint every coupling the inventory found:
  - the anchor in `docs/tools/phpstan.md:99`;
  - `docs/tools/shellCheck.md:33`;
  - the new-tool procedure in `CLAUDE/tool-boundaries.md:70-72` (it no longer names a
    `CLAUDE.md` tool list);
  - the docblock in `ForbidUnanchoredVendorSubstringCheckRule.php:28`;
  - the comments in `IssueFormsTest.php`;
  - `.claude/hooks/README.md:419`.
- [ ] ⬜ **Task 4.2**: Add the path rules (prefix `phpqaci-`, R7a pointer shape), each pointing
  at the canonical homes. Use the globs the inventory validated against `git ls-files`:
  shell scripts (including `bin/**` for extensionless stubs), pipeline source, config,
  PHPStan rules, Claude deploy, tool delivery and releases.
- [ ] ⬜ **Task 4.3**: Set `autoMemoryEnabled: false` in `.claude/settings.json`.

### Phase 5: Verification and independent review

- [ ] ⬜ **Task 5.1**: Deterministic checks:
  - the `@`-import audit (no `^\s*@` or `[@` outside fences in any loaded file);
  - every link and anchor in `CLAUDE.md` and `CLAUDE/*.md` resolves;
  - the docs-qa sweep reports no `duplicate-block` for `CLAUDE.md`;
  - every row of `FACT-MAP.md` resolves to a present line;
  - the generated regions are byte-identical;
  - the token measurement is recorded.
- [ ] ⬜ **Task 5.2**: Live trigger test: touching one file per path rule injects that rule,
  including an extensionless `bin/` stub.
- [ ] ⬜ **Task 5.3**: A fresh, sceptical reviewer re-audits from the files alone. It redoes the
  import audit, the knowledge preservation against `git show <baseline>:CLAUDE.md`, the SSoT
  check and the globs, and it looks for regressions this work introduced. Fix every major
  finding.
- [ ] ⬜ **Task 5.4**: Run the pre-push gate (`CLAUDE/prepush-verification.md`), open the pull
  request, run the PR verification (`CLAUDE/pr-verification.md`), then merge.

## Success Criteria

- [ ] The hand-written part of `CLAUDE.md` is ≤ about 3k tokens (chars ÷ 4), measured and
  journalled. Both generated regions are unchanged in content.
- [ ] Every Owner ruling is resident, each marked `binding-floor` and linking an existing canon.
- [ ] `FACT-MAP.md` accounts for every fact in the baseline hand-written text.
- [ ] No `@`-imports. No broken links or anchors. No docs-qa `duplicate-block` involving
  `CLAUDE.md`.
- [ ] Each path rule is shown to load on a matching file, live.
- [ ] The independent reviewer reports no unresolved major findings, and the pre-push gate is
  green.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00022-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan drafted; inventory and mechanics reports in `subagent-reports/`.
