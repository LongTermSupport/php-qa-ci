# Plan 00022: handoff after Phase 3

Written by the executing sub-agent at its context budget. The next agent starts here.

## Done

- Step 0: the five refuted fact-check items are corrected in PLAN.md, DECISIONS.md and
  FLOOR-CLAUSES.md (`636ea8dc`).
- Phase 1 (`0e5aa7d8`): baseline journalled. Task 1.2 (a)/(b) are evidenced by the coordinator's
  live session (journal entry "live loading baseline from the coordinator"); (c) `/context` is
  blocked because a spawned `claude -p` is not logged in.
- Phase 2 (`6ba501a1`): `CLAUDE/DocumentationStrategy.md` project additions;
  `.claude/rules/phpqaci-reference-docs.md`; DBF disposition journalled.
- Phase 3: 3.1 `c9ee6e1a`, 3.2 `cb637340`, 3.3 `8a22bb58`, 3.4 `8bab8cf5`, 3.5 in four commits
  `b12eab18` (pipeline order + roster removal), `2e6686c6` (configuration), `eea999b3` (consumer
  reference sections), `1b4d4894` (Tools Reference). `FACT-MAP.md` covers every baseline section;
  the Tools Reference rows are in `subagent-reports/261019-tools-reference-fact-map.md`.

## State of root CLAUDE.md

680 lines. Every relocated section is gone; what remains is the original binding sections
(Knowledge & Memory, DBF, Segfaults, Releases, Tools stay current, Issue loop), short interim
binding sections written during Phase 3 (Work on php8.5, Dogfooding, Bash vs PHP, Not a tool
proxy), interim pointer sections (Pipeline order, Configuration, Claude Code integration, Managed
source, Consumer-facing reference), the generated active-defences region (untouched) and the
`<hooksdaemon>` block (untouched). The interim sections are raw material for Task 4.1, not the
final shape.

## Next: Task 4.1

Rewrite everything outside the two generated regions as: a short orientation (no `## Overview`
heading), the binding floor under the single block-level marker
`<!-- binding-floor: each bullet cites its canon -->` (one bullet per ruling, 1–4 lines, ending
in its canonical link; every FLOOR row of `FACT-MAP.md` / `FLOOR-CLAUSES.md` F01–F13 present;
the memory bullet says "canonical here"), and the navigation map (task → document, plain links,
no `@`): architecture, tool-boundaries, dogfooding, release-lines, claude-code-integration,
docs/pipeline.md, docs/configuration.md, `rule-doc`/`bin/qa -h`, qa-orchestration,
prepush-verification, pr-verification, branch-policy, PlanWorkflow, PlanJournalling, Worktree,
DocumentationStrategy, managed-source. Keep the active-defences region where it is, between the
floor and the map or after them; never hand-edit it. Target ≤ about 100 hand-written lines,
≤ 3k tokens.

Then Task 4.2 (`bin/qa -t mdf`, `php bin/rules . --write-agent-summary=CLAUDE.md`, re-format,
`AgentContextIsCurrentTest`), Phase 5 (nested routing files, path rules, `autoMemoryEnabled`),
Phase 6.

## Gotchas

- Bash compound commands need `&&` chains (the daemon denies `;` sequences without a safety
  prelude); `sed` is denied outright, including `sed -n` (use `awk`).
- Journal only through `bash CLAUDE/Plan/mkplan.bash --journal 22 <category> <body-file>`.
- Helper scripts in `untracked/scratch/` (gitignored): `cut.py` replaces a CLAUDE.md span between
  two exact heading lines; `tick.py` ticks PLAN.md tasks; `append.py` appends to FACT-MAP.md.
- `composer install` has been run in the worktree; the bin dir is `bin/`
  (`php bin/phpunit -c qaConfig/phpunit.xml --no-coverage <test>`).
- The daemon CLI is `/workspace/.claude/hooks-daemon/bin/hooks-daemon`; pass an absolute
  `--project-root`.
- FACT-MAP keys are verbatim substrings; some span a line wrap at the destination, so check them
  with whitespace normalised.
- Coordinator asks the final reply to list, for each new path rule and nested CLAUDE.md, one
  file that should trigger it and one that should not. So far only
  `.claude/rules/phpqaci-reference-docs.md` exists: triggers on `docs/tools/rector.md`, not on
  `docs/ci.md`.
