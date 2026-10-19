# Plan 00022 review (independent, sceptical second pass)

Reviewer scope: `PLAN.md`, the inventory report, the mechanics report, `CLAUDE/core/DocumentationStrategy.core.md`,
`CLAUDE/DocumentationStrategy.md`, `.claude/rules/*.md`, the current `CLAUDE.md`, and spot checks against the
repository (`git ls-files`, `git grep`, reads). Read-only apart from this file.

## Verdict: APPROVE-WITH-CHANGES

The core design is right: a binding floor plus a navigation map, generated regions left alone, duplicates retired
only after a per-fact check, and path rules used as accelerators only. The measurements hold up. The plan is not yet
optimal, though. It skips one belt mechanism the gist names, nested `CLAUDE.md`, which is the one mechanism that
closes the plan's own vendor-dogfooding gap. It presents Decision 1 as daemon-compliant when it deviates from the
daemon's R2/R3. It leaves the largest remaining resident cost with no action at all. It has commit-ordering and
generated-block mechanics that will not work as written. And it fixes a defect class without a detector, which the
binding Defence Before Fix rule forbids. Numbered required changes R1–R13 below. Execution may start once they are
folded into PLAN.md.

## Verified claims (spot-checked)

- Sizes: `CLAUDE.md` is 1547 lines and 170 409 chars. The hand-written part is 69 982 chars and the `<hooksdaemon>`
  block 90 500 chars, starting at line 1077. The active-defences markers are at lines 40 and 122. The inventory
  figures are correct.
- Couplings: `docs/tools/phpstan.md:99` anchor, `docs/tools/shellCheck.md:33`, `CLAUDE/tool-boundaries.md:70-72`,
  `ForbidUnanchoredVendorSubstringCheckRule.php:28`, `IssueFormsTest.php:16,42`, `.claude/hooks/README.md:419`, and
  the `update-deps.yml:95` / `bin/rules --write-agent-summary=<file>` contract. All confirmed. One the plan omits:
  `src/PHPStan/Rules/RequireDeclareStrictTypesRule.php:18` ("per CLAUDE.md convention"). It is harmless, because
  the active-defences region still names `missingStrictTypes`.
- No test reads `docs/pipeline.md` or `docs/phpqa-tools.md`; checked with `git grep` over `tests/` and `src/`.
- `markdown_organization` allows `CLAUDE.md` anywhere (`is_adhoc_instruction_file`), so nested `CLAUDE.md` files
  are not blocked.
- The changelog watched paths (`qaConfig/qa.php:56-80`) include `src/`, `bin/`, `scripts/` and `templates/`, but
  not `docs/`, `CLAUDE/` or `CLAUDE.md`.
- There is no `.gitattributes`, so `CLAUDE.md`, `CLAUDE/` and `docs/` all ship in the dist.
- The branch base is a problem. `chore/claude-md-progressive-disclosure` sits on `e5df874c`, which belongs to the
  unmerged `chore/hooks-daemon-v3.70.0`: three commits not on `origin/php8.5`. `/workspace` already has `8d0a12ba`,
  which this branch lacks.
- Plan factual slips. The Overview says "four Owner rulings exist only here", but the inventory (§7 item 9) and
  Decision 3 list five: knowledge & memory, release lines, dogfooding, Bash vs PHP, and not a tool proxy. The
  Overview also states "rules do not load from `vendor/`" as fact before Task 1.2 has verified it.

## Assessment against the brief

1. **Goal / better design.** The plan cuts the hand-written part from 17.5k to about 2–3k tokens, which is
   correct and near the floor. Two things are missing for "maximal" and "reliable surfacing":
   - **Nested `CLAUDE.md` (R2/R7d) is the better belt for directory-shaped subtrees.** It loads lazily both in
     this repo and when dogfooding from `vendor/lts/php-qa-ci/`, because nested files under the cwd load on touch.
     That is exactly the case the plan says path rules cannot cover. The daemon sanctions it: R7d routing tables
     of about 15 lines, or registered module docs. The repo already has the pattern
     (`templates/src-PHPStan-CLAUDE.md`, `CLAUDE/Plan/CLAUDE.md`). The plan never evaluates it, and the gist names
     it explicitly.
   - The `<hooksdaemon>` block, about 22.6k raw tokens, is excluded with no action item. After this plan it is
     more than 80% of the resident file. Measured by chars ÷ 4, about 11.8k tokens of the table are mdformat
     column padding (20.9k raw against 9.1k squeezed). The real token cost of whitespace runs is lower than
     chars ÷ 4, which is a further reason to measure properly.
2. **Decisions.**
   - **Decision 1** (`docs/` canonical for consumer-visible behaviour) is the right call on substance. DBF needs a
     page per identifier, `rule-doc` resolves into `docs/`, and those pages ship and are read offline. But the
     plan calls it daemon-compliant ("where the two differ, the daemon's enforced rules win"), and it is not. Core
     R2/R3 says the agent tree owns the depth for every fact. The daemon-owned `.claude/rules/human-docs.md`
     (`paths: docs/**/*.md`) will tell every agent editing `docs/` to "summarise and point into the agent tree",
     which is the opposite of Task 2.4. It has to be declared as a deviation and reconciled at the point of use.
   - **Decision 2** (retire after a per-fact check, with a FACT-MAP) is the correct reading of "relocate, never
     delete" under the core's transition rule. Approve.
   - **Decision 4** (no new skills) is approved. The justification should also say that project skills ship to
     every consumer through the deploy manifest, so a new skill's description would be resident in consumer
     sessions too. The intent-keyed navigation map is the intent brace, and it also works when dogfooding, where
     php-qa-ci's `.claude/skills` would not load either.
3. **Tasks and criteria.** They are mostly concrete. The faults are: the coupling repoints (Task 4.1) land after
   the removal (Task 3.1), which breaks anchors and leaves the commit non-atomic; Task 1.2 does not say how a
   "fresh session" or a "consumer vendor" is set up; Task 3.2 asks for something impossible in a linked worktree
   (a fresh daemon regeneration); and Task 5.1 names checks without naming the instruments. The ≤3k ceiling is
   about right (inventory skeleton ≈2.0–2.3k). Keep it as the hard ceiling, aim at about 2.2k, and add a line cap.
4. **Binding content at risk.** The floor list covers every ruling heading. The risk is clause loss during
   condensation. Several binding clauses are easy to drop: "start **and end** of every session", "unreplied
   issues", "standing authorisation after a **fresh** sub-agent's verification", "pushing `php8.5` deploys to
   production", "#36 is the only backport exception", "an issue states its release line", "`php8.3` is dead",
   "suppression / baseline / narrowing is an Owner decision", "a new check needs an identifier, a page and a
   `bin/rules` entry", "segfault = halt even under a reported success (exit 139 or a kernel-log line)", "never
   tag or choose versions by hand", "approved authors are listed once in `.claude/hooks-daemon.yaml`", and "the
   working default is writable; reproduce CI with `QA_READONLY=1`". Nothing in the plan checks clause by clause.
5. **Uncovered risks.**
   - The daemon's auto-commit and mdformat pass over the whole of `CLAUDE.md`.
   - A guaranteed `<hooksdaemon>` merge conflict.
   - Changelog obligations for watched paths. The `src/` docblock is one; nested `CLAUDE.md` files under
     `src/`, `scripts/` or `bin/` would be others.
   - The `markdownFormat` lane does not cover `.claude/rules`. Correctly so: those are daemon-owned files.
     Formatting still happens on Write/Edit, so leave the lane alone.
   - Consumer impact. Today a consumer agent that reads any file under `vendor/lts/php-qa-ci/`, including a `cat`
     of a `docs/tools` page, lazily loads this 42.6k-token `CLAUDE.md`, together with a `<hooksdaemon>` block that
     describes this repo's handlers, not the consumer's. The plan is a consumer win as well and should say so.
     The shipped hooks block is a follow-up question.
6. **Gist items skipped without justification.** Nested `CLAUDE.md` (see 1); honest whole-file reporting (Goals
   report only the editable part); live verification of surfacing, beyond loading mechanics.

## Required changes

**R1 — Reframe Decision 1 as a declared deviation.** Replace the Overview sentence "Where the two differ, the
daemon's enforced rules win" with: "Where the two differ, the daemon's rules win, except for Decision 1, a recorded
project deviation from core R2/R3." Add to Decision 1 and to Task 2.5:

> "This deviates from core R2/R3 (agent tree owns all depth). Justification: DBF TOOLING-SPEC requires an offline
> page per identifier, `rule-doc` resolves into `docs/`, and those pages ship to consumers. The daemon-owned
> `.claude/rules/human-docs.md` will still advise 'point into the agent tree' on every `docs/` edit. A project rule
> `phpqaci-reference-docs.md` (paths `docs/tools/**`, `docs/phpstan-rules/**`, and the consumer-behaviour pages
> touched here) states the exception and links `CLAUDE/DocumentationStrategy.md`."

Also consider an upstream request for a daemon option that marks a human-tree subtree as canonical reference
documentation.

**R2 — Add Decision 7: the trigger mechanism per subtree, including nested `CLAUDE.md`.** Suggested wording:

> "Directory-shaped triggers that matter when dogfooding get an R7d **routing-table** `CLAUDE.md`: no more than 15
> lines, pointers only, no restated rules. Candidates are `src/Pipeline/CLAUDE.md` (→ `CLAUDE/architecture.md`,
> `CLAUDE/tool-boundaries.md`), `src/PHPStan/CLAUDE.md` (→ `CLAUDE/DefenceBeforeFix.md`) and `scripts/CLAUDE.md`
> (→ the Bash-vs-PHP canon). These load lazily both here and from `vendor/lts/php-qa-ci/`. File-shaped or
> cross-cutting triggers keep `.claude/rules` path rules: `CHANGELOG.md`, `**/*.bash`, extensionless `bin/`
> stubs, `phive.xml`, `composer.json` and `.github/workflows/*`. No subtree is covered by both. No `CLAUDE.md`
> goes under `docs/`, where the `docsProse` and `markdownLinks` lanes scan every page and the pages ship as
> consumer documentation."

Task 4.2 then splits into 4.2a (nested routing files) and 4.2b (path rules). Each nested file under `src/`,
`scripts/` or `bin/` is a changelog-watched path (see R8).

**R3 — Make Task 1.2 executable and extend it.** Suggested wording:

> "Use headless `claude -p` sessions with a scratch `settings.json` that registers an `InstructionsLoaded` hook
> logging to `untracked/scratch/`. (a) In this worktree: a sentinel `paths:` rule loads on a matching Read, and
> only then; a plain link does not load its target. (b) In a scratch consumer under `untracked/scratch/consumer/`,
> whose `vendor/lts/php-qa-ci` is a git worktree of this branch: reading `vendor/lts/php-qa-ci/src/Pipeline/X.php`
> loads php-qa-ci's root `CLAUDE.md` and a sentinel `src/Pipeline/CLAUDE.md`, but not php-qa-ci's `.claude/rules`.
> (c) Record the real resident size from `/context` (or the token-count API) for the baseline file."

Until (b) passes, the Overview's vendor claim reads "expected".

**R4 — Make the removal and the repoints atomic.** Move Task 4.1, and the `tool-boundaries.md` new-tool step, so
each repoint lands **in the same commit** as the removal of the section it points into. Repoint to the Phase 2
homes, which already exist by then. Otherwise `pointer-resolves` flags the broken
`#preflight-phase-configuration--setup` anchor, and the new-tool procedure points at a list that no longer exists.

**R5 — Add a binding-clause checklist.** Add to Task 2.4:

> "FACT-MAP marks each binding clause FLOOR. Task 3.1 must keep every FLOOR row resident in the floor bullet (the
> warning, not the procedure). Task 5.3's reviewer checks each FLOOR row against the new `CLAUDE.md`."

Seed it with the clauses listed under assessment item 4.

**R6 — Replace Task 3.2's generated-region mechanics.** Suggested wording:

> "The `<hooksdaemon>` block is not regenerated in the worktree; the injector skips linked worktrees. It is kept
> byte-identical to the target branch's block, verified by extracting the span and diffing it against `git show origin/php8.5:CLAUDE.md`. On a merge conflict, take php8.5's block verbatim. Before committing, run the daemon's
> own formatter over `CLAUDE.md` (`bin/qa -t mdf`, or `hooks-daemon format-markdown CLAUDE.md`) so the daemon's
> restart pass finds nothing to reflow. Then regenerate the active-defences region with `php bin/rules . --write-agent-summary=CLAUDE.md`, re-run the formatter, and confirm that `AgentContextIsCurrentTest` passes."

Drop "any formatter reflow is reverted": the daemon reformats on restart, and only idempotence survives that.

**R7 — Declare the branch dependency.** Add to Delivery:

> "This branch stacks on `chore/hooks-daemon-v3.70.0`, which is not yet on `php8.5`. The pull request opens only
> after that one merges, and after `php8.5` has been merged into this branch."

**R8 — Add a changelog task.** Add Task 5.4a:

> "Each commit that touches a watched path carries a `## Unreleased` entry or a `Changelog: none — <reason>`
> trailer. That covers the `src/` docblock and any nested `CLAUDE.md` under `src/`, `scripts/` or `bin/`. Add a
> `Changed` entry if the slimmer vendored `CLAUDE.md` is consumer-noticeable, since consumer agents that read
> vendored files lazily load it."

**R9 — Report the whole file honestly and act on the dominant cost.** Add to Goals:

> "The whole file goes from about 42.6k to about 27k raw tokens (−36%), measured with `/context` as well as
> chars ÷ 4. Afterwards the `<hooksdaemon>` block is more than 80% of the resident file."

Add Task 5.5:

> "File an upstream report with `hooks-daemon issue-report` (mdformat column padding inflates the injected rules
> table: 83.7k chars raw against about 36k squeezed; ask for a compact rendering). Put an Owner-decision note in
> the journal listing the handlers whose rows cost the most."

**R10 — Apply Defence Before Fix to the stale-roster defect class.** The stale facts (`deadCode` missing, broken
phase numbering, Yaml Lint called a platform lane) are one class: a hand-maintained tool roster drifting from
`ToolRegistry`. No detector exists. `CLAUDE/tool-boundaries.md` says "the tests will tell you", but no test reads
`docs/pipeline.md`. Before Decision 5 fixes them, add one of the following.

- (a) A Small test asserting that the lane names in `docs/pipeline.md`'s phase sections equal
  `ToolRegistry::defaults()` in order, including gates.
- (b) A generated roster region in `docs/pipeline.md`, written by the same generator mechanism as the active
  defences and regenerated in `update-deps.yml`.
- (c) Replace the roster with a pointer to `bin/qa -h` (R5).

If none of these is wanted, it is an Owner decision, recorded in the plan. Turn the `docs/phpqa-tools.md`
follow-up into a filed issue, not a note.

**R11 — Pin down the marker placement.** Use one **block-level** `<!-- binding-floor: each bullet cites its canon -->` comment on its own line before the floor list. Do not put per-bullet comments inside list items. The
docs guarantee stripping only for block-level comments, and mdformat may fold an in-list comment into the item.
Bullets that are themselves the canon (knowledge & memory, release lines) say so in prose ("canonical here"), so
the R4a marker is not claimed for a restatement that has no other source.

**R12 — Name the instruments in Task 5.1.**

- The `hooks-daemon docs-qa --sweep --json` findings `pointer-resolves`, `duplicate-block`, `rules-file-shape` and
  `module-doc-budget`.
- `hooks-daemon docs-qa --lint <file>` on every new rule file and nested `CLAUDE.md`.
- `bin/mdlinks`, which covers `README.md` and `docs/` only, for the `docs/` edits.
- `AgentContextIsCurrentTest`.
- The token figures from `/context`.

**R13 — Add a surfacing check (the Owner asked for "reliable surfacing").** Add Task 5.2a:

> "Five headless scenario prompts from a fresh session, each recording which canonical doc the agent opened before
> acting: add a new lane; fix a php-qa-ci bug from a consumer's `vendor/`; prepare a release entry; a test exited
> 139; tune a consumer's `qa.php`. A miss is a navigation-map or trigger defect, fixed before merge."

## Optional suggestions

- **O1.** Add a small ratchet test (`RootClaudeMdBudgetTest`) that caps the hand-written byte count outside the two
  generated regions. Re-growth is the defect class this plan fixes, and a detector fits DBF. Target about 2.2k
  tokens, ceiling 3k, at most about 100 lines.
- **O2.** Give the release-line ruling a `CLAUDE/release-lines.md` canon. `IssueFormsTest` encodes it in code, so
  a canon in `CLAUDE/` gives the test comment a stable link target.
- **O3.** Split "not a tool proxy" into a contributor half (`CLAUDE/architecture.md`) and a consumer half (a short
  paragraph in `docs/pipeline.md` or `docs/configuration.md`), consistent with Decision 1.
- **O4.** Narrow the `**/*.sh` glob to exclude `.claude/**` (daemon-owned scripts). Do not add `.claude/rules` to
  `withMarkdownFormatPaths()`: those files are daemon-owned and formatting them would show up as deployed-artefact
  drift.
- **O5.** Record whether `documentation.qa.generated_docs` can declare the active-defences **region** (R10). If it
  cannot, record the accepted advisory.
- **O6.** Follow-up question for the Owner: the vendored `CLAUDE.md` carries this repo's `<hooksdaemon>` block into
  consumer sessions whenever a consumer agent reads a php-qa-ci file. That is guidance for the wrong project.
  Worth an issue.
- **O7.** FACT-MAP rows: key them per paragraph or bullet with stable fact IDs, rather than per sentence, so the
  map stays reviewable. Its R8 disposition at completion is "historical".
