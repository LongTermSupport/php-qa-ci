# Plan 00022: mechanics, constraints and decisions

Supporting document for [PLAN.md](PLAN.md). The plan's tasks cite the decisions here by number.

## Verified loading mechanics

Source: [subagent-reports/261019-loading-mechanics-claude-code-guide.md](subagent-reports/261019-loading-mechanics-claude-code-guide.md)
(the official Claude Code memory, skills and plugin docs). Task 1.2 re-checks them live.

- Root `CLAUDE.md` loads at launch and is re-read after `/compact`. Nested `CLAUDE.md` files
  under the working directory load lazily, when a file in their subtree is read or edited.
- `@path` imports inline eagerly, up to four hops, and are skipped inside code spans and fences.
  This repository has none, and the plan adds none.
- `.claude/rules/*.md` with `paths:` load only when a matching file is touched; without `paths:`
  they load always. `paths:` is the only frontmatter field Claude Code reads.
- **Block-level** HTML comments in `CLAUDE.md` are stripped before injection. Inline comments
  are not covered by the docs.
- Auto memory is switched off by `autoMemoryEnabled: false` (or
  `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`), separately from `CLAUDE.md` loading.
- Each skill's name and description are resident every turn.
- The `InstructionsLoaded` hook and `/context` show which instruction files loaded and why.

## Repository constraints

- **R7a**: a `.claude/rules/*.md` file is pointers only: at most two imperative lines and a link,
  no tables, fences or procedures, body capped at 15 non-blank lines. Never a content
  destination.
- **R7d**: a sub-folder `CLAUDE.md` is either a pure routing table (about 15 lines) or a
  registered module doc. This plan adds routing tables only.
- **R4a**: a safety-critical rule may be restated at the point of use, citing its canon, with a
  greppable marker. This plan uses **one block-level** comment,
  `<!-- binding-floor: each bullet cites its canon -->`, on its own line before the floor list,
  never per-bullet comments inside list items (stripping is only guaranteed for block-level
  comments, and mdformat may fold an in-list comment into the item). A bullet that is itself the
  canon says "canonical here" in prose and claims no R4a restatement.
- **R5**: a derived roster (tool list, phase order, gates) is stated only by its generator
  (`ToolRegistry`, which produces `bin/qa -h` and the active-defences region).
- **Defence Before Fix §3.2** ([CLAUDE/DefenceBeforeFix.md](../../../DefenceBeforeFix.md)): a test
  must not serve as the Detector.
- The daemon writes `<hooksdaemon>`, re-runs its markdown formatter over the whole of
  `CLAUDE.md`, and auto-commits the file on a restart in the main checkout. Its injector skips
  linked worktrees, so all editing happens in this plan's worktree.
- The active-defences region stays in `CLAUDE.md` (DBF TOOLING-SPEC 7.2: a file agents already
  load). `AgentContextIsCurrentTest` and `update-deps.yml` hard-code the file.

## Decisions

1. **SSoT direction, a declared deviation from core R2/R3.** `docs/tools/`,
   `docs/phpstan-rules/` and the consumer-behaviour pages under `docs/` stay canonical for
   anything a consumer can observe; `CLAUDE/` owns agent-only material (architecture and its
   invariants, contributor procedures, Owner rulings). This deviates from core R2/R3 (the agent
   tree owns all depth). Justification: DBF TOOLING-SPEC requires an offline page per
   identifier, `rule-doc` resolves into `docs/`, and those pages ship to consumers. The
   daemon-owned `.claude/rules/human-docs.md` will still advise "point into the agent tree" on
   every `docs/` edit, so a project rule `phpqaci-reference-docs.md` (paths `docs/tools/**`,
   `docs/phpstan-rules/**` and the consumer-behaviour pages this plan touches) states the
   exception and links `CLAUDE/DocumentationStrategy.md`. The rejected alternative, moving the
   depth into `CLAUDE/` and thinning `docs/`, rewrites the shipped documentation for no agent
   benefit.
2. **Retire, never copy, what `docs/` already says.** A section that duplicates a `docs/` page is
   removed only after a per-fact check; each fact found nowhere else is first added to its
   canonical page in the same commit.
3. **Every ruling has a canon.** Knowledge & memory stays canonical in root (its floor bullet is
   the canon). The release-line policy gets `CLAUDE/release-lines.md`, which also gives
   `IssueFormsTest`'s comments a stable link target; it links `README.md#branches-and-versions`
   for what README already states rather than restating it. Dogfooding gets `CLAUDE/dogfooding.md`.
   "Bash is for invocation" and the contributor half of "not a tool proxy" go to
   `CLAUDE/architecture.md`; the consumer half of "not a tool proxy" goes to
   `docs/configuration.md` (Decision 1).
4. **No new skills.** Every skill's description is resident on every turn, and project skills
   ship to every consumer through the deploy manifest, so a new description would be resident in
   consumer sessions too. The intent-keyed navigation map is the intent brace. (Nested
   `.claude/skills/` are documented to load when a file in their subdirectory is touched, so
   skills may work when dogfooding too; the decision rests on the resident-description cost
   alone.)
5. **The stale-roster class is removed at its source (DBF, review R10).** The stale facts
   (`deadCode` missing, its gate missing, broken phase numbering, Yaml Lint called a platform
   lane) are one class: a hand-maintained tool roster drifting from `ToolRegistry`. Option (a), a
   test comparing `docs/pipeline.md` to `ToolRegistry::shipped()`, is excluded by DBF §3.2.
   Option (b), a generated roster region in `docs/pipeline.md`, needs a new generator in `src/`.
   Chosen: option (c), per R5. Root `CLAUDE.md` keeps no roster, and `docs/pipeline.md`'s
   numbered per-phase lane lists and gate sentence become each phase's purpose plus a pointer to
   `bin/qa -h`, the identifier index `docs/phpstan-rules/README.md` and
   [ToolGateEnum](../../../../src/Pipeline/Tool/ToolGateEnum.php). The remaining hand roster,
   `docs/phpqa-tools.md`, is filed as an issue for retirement (Task 6.6). The other stale facts
   (`qaConfig/pipeline.php` and `--agent-mode` missing from preflight, "project's vendor/bin is
   used", "validation only") are corrected where their text lands.
6. **Auto memory is switched off in settings.** `.claude/settings.json` has no
   `autoMemoryEnabled` key today; `autoMemoryEnabled: false` is added, enforcing the memory
   policy at the harness level. The memory store is empty, so nothing migrates.
7. **One trigger mechanism per subtree.** Directory-shaped triggers that matter when dogfooding
   get an R7d routing-table `CLAUDE.md` (at most 15 lines, pointers only, no restated rules):
   `src/Pipeline/CLAUDE.md` (→ `CLAUDE/architecture.md`, `CLAUDE/tool-boundaries.md`),
   `src/PHPStan/CLAUDE.md` (→ `CLAUDE/DefenceBeforeFix.md`) and `scripts/CLAUDE.md` (→ the
   Bash-vs-PHP canon). These load lazily here and from `vendor/lts/php-qa-ci/`. File-shaped or
   cross-cutting triggers keep path rules: `CHANGELOG.md`, `**/*.bash`, extensionless `bin/`
   stubs, `phive.xml`, `composer.json`, `.github/workflows/*`. No subtree is covered by both. No
   `CLAUDE.md` goes under `docs/`, where `docsProse` and `markdownLinks` scan every page and the
   pages ship. The new `src/PHPStan/CLAUDE.md` is distinct from the consumer guardrail
   `templates/src-PHPStan-CLAUDE.md`, which `scripts/lib/deploy-phpstan-scaffold.inc.bash`
   copies into a consumer's `src/PHPStan/` only when no `CLAUDE.md` is there.
