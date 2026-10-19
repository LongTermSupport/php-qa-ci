# Plan 00022: root CLAUDE.md inventory (sub-agent report)

Scope: worktree `/workspace/untracked/worktrees/docs-pd`, branch `chore/claude-md-progressive-disclosure`.
The work was read-only: nothing was edited except this report, and nothing was committed. Line numbers refer to the
worktree's `CLAUDE.md` as it stands. Its `<hooksdaemon>` block is a newer regeneration than the
one in `/workspace`.

The daemon's own rules shape every recommendation below, so they come first:

- **R7a** (`CLAUDE/core/DocumentationStrategy.core.md`) says a `.claude/rules/*.md` file is
  POINTERS ONLY. It holds frontmatter, a trigger, at most two imperative lines and a link. It may
  not contain fences, tables, numbered procedures or quotes, and its body is capped at 15
  non-blank lines. **Path rules therefore cannot be a destination for relocated content.** The
  content goes into `CLAUDE/*.md`, and the path rule only points at it.
- **R4a** lets a safety-critical rule be restated in compressed prose at the point of use. The
  restatement must cite its canon, carry no tables, steps or counts, and carry a greppable HTML
  marker. `CLAUDE/DocumentationStrategy.md` records no marker spelling yet, so the plan has to
  choose one there.
- **R5** says a derived roster (the tool list, phase numbering) is stated only by its generator.
  For tools that generator is `ToolRegistry`, which also produces the `bin/qa -h` usage text and
  the generated active-defences region.
- **R11** says root `CLAUDE.md` carries "a pointer plus the shortest possible resident summary".

---

## 1. Measured current cost

Token estimates are chars ÷ 4. The "squeezed" figures collapse runs of whitespace first. That
matters for the daemon block, whose mdformat-aligned tables are mostly padding.

| Slice                                                             | Lines | Words  | Chars   | ≈ tokens (raw) | ≈ tokens (squeezed) |
| ----------------------------------------------------------------- | ----- | ------ | ------- | -------------- | ------------------- |
| **Whole root `CLAUDE.md`**                                        | 1547  | 15 266 | 170 409 | **42.6k**      | 27.3k               |
| Editable (lines 1–39 + 123–1076)                                  | 993   | 7 863  | 69 982  | **17.5k**      | 16.6k               |
| Generated: active defences, lines 40–122 (markers included)       | 83    | 892    | 9 927   | 2.5k           | 2.5k                |
| Generated: `<hooksdaemon>` block, lines 1077–1547                 | 471   | 6 511  | 90 500  | **22.6k**      | 10.8k               |
| — of which the "All other enforced rules" table (lines 1096–1378) | 283   | 5 619  | 83 664  | 20.9k          | 9.1k                |

Other content that is always loaded:

- **`.claude/rules/*.md`**: none. All 8 files (agent-docs, claude-agents, claude-skills,
  human-docs, plan-dir, remote-docs, source-dirs, test-dirs) have `paths:` frontmatter, so they
  load only when a matching file is touched. All 8 are daemon-owned
  (`hooks-daemon-rule-version: 1.0.0`).
- **Skill frontmatter** (name and description) of the 11 project skills under `.claude/skills/`:
  5 952 chars, about 1.5k tokens. `qa` (698 chars) and `qa-tool-runner` (647 chars) are the
  largest. Plugin and built-in skills are not counted.
- **`@`-imports**: none, in `CLAUDE.md` or anywhere else that is loaded.
- `.claude/settings.json` has no `claudeMdExcludes` and no other context-loading key.
- Nested `CLAUDE.md` files are lazy-loaded and so not resident. Examples:
  `.claude/hooks/CLAUDE.md`, and `.claude/hooks-daemon/CLAUDE.md` in the main checkout, which is
  large.

The plan can only shrink the editable 17.5k tokens. That is about 41% of the file raw, or 61%
squeezed. The `<hooksdaemon>` block is larger than everything editable put together. Its size
follows from the handlers that are enabled. The daemon's only size knob,
`claude_md.promotion.promoted_handlers`, already ships empty, which is the minimum. Shrinking the
block further means disabling handlers, which is an Owner decision and out of scope.

---

## 2. Section inventory (root `CLAUDE.md`)

Classification keys:

- **FLOOR**: stays resident in condensed form.
- **EXTRACT**: moves to a `CLAUDE/*.md` file.
- **DUP**: already exists elsewhere; the column names the single source of truth.
- **GEN**: generated and immovable.
- **PATH**: the trigger is a path rule that points at the new home.

| Heading                                                                | Lines     | Size (lines/chars) | Class                                                                                                                                                                                                                                                                                     | Proposed destination                                                                                                                                                                                                 | Trigger                                                                                     | Notes                                                                                                                                                                                                                                                                                                                                                                       |
| ---------------------------------------------------------------------- | --------- | ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `# PHP-QA-CI Library Documentation`                                    | 1–2       | 2 / 35             | FLOOR                                                                                                                                                                                                                                                                                     | Retitle, e.g. "php-qa-ci — agent context"                                                                                                                                                                            | —                                                                                           |                                                                                                                                                                                                                                                                                                                                                                             |
| Knowledge & Memory Policy (binding)                                    | 3–14      | 12 / 768           | FLOOR                                                                                                                                                                                                                                                                                     | Stays, about 3 lines. The links to prepush, qa-orchestration and pr-verification move to the navigation map                                                                                                          | resident                                                                                    | Root `CLAUDE.md` is the **only** home for this ruling; no `CLAUDE/*.md` states it. Either keep it as canon here, or create `CLAUDE/knowledge-policy.md` and leave a 2-line floor.                                                                                                                                                                                           |
| Defence Before Fix (binding)                                           | 15–39     | 25 / 1 602         | FLOOR (condense)                                                                                                                                                                                                                                                                          | About 5 lines: "Found a defect? Build the detector first"; a new check is a defence (identifier, page, `bin/rules`); a suppression is an Owner decision. → `CLAUDE/DefenceBeforeFix.md`, `CLAUDE/tool-boundaries.md` | resident, plus the `defence-before-fix` skill                                               | The vendored-spec paragraph (remote-docs paths, `extra.defence-before-fix`, `DefenceBeforeFixDeclarationTest`) moves to DefenceBeforeFix.md. Verify it is not already there.                                                                                                                                                                                                |
| php-qa-ci — Active defences                                            | 40–122    | 83 / 9 927         | **GEN**                                                                                                                                                                                                                                                                                   | Stays in `CLAUDE.md`. See §5 and §7                                                                                                                                                                                  | resident                                                                                    | Written between `<!-- phpqaci-active-defences:start/end -->` by `bin/rules . --write-agent-summary=CLAUDE.md`. The lane lines already list every tool with its `docs/tools/*.md` page, which makes the hand-written Tools Reference redundant (R5).                                                                                                                         |
| Segfaults are a halt condition (binding)                               | 124–133   | 10 / 631           | FLOOR                                                                                                                                                                                                                                                                                     | About 2 lines → `CLAUDE/segfault-policy.md`                                                                                                                                                                          | resident                                                                                    | The archetype and the coredumpctl caveat are already in segfault-policy.md, so drop them here.                                                                                                                                                                                                                                                                              |
| Releases come from the changelog (binding)                             | 134–142   | 9 / 617            | FLOOR                                                                                                                                                                                                                                                                                     | About 2 lines → `CLAUDE/releases.md`                                                                                                                                                                                 | resident, plus a path rule on `CHANGELOG.md`                                                | The trailer syntax is in releases.md (lines 45–51).                                                                                                                                                                                                                                                                                                                         |
| Tools stay current (binding, Owner)                                    | 143–151   | 9 / 666            | FLOOR                                                                                                                                                                                                                                                                                     | About 3 lines, keeping the session start/end `gh issue list` check → `CLAUDE/tool-currency.md`                                                                                                                       | resident                                                                                    | The standing authorisation and fresh-sub-agent verification are already in tool-currency.md (lines 30–48).                                                                                                                                                                                                                                                                  |
| The GitHub issue loop                                                  | 152–158   | 7 / 362            | FLOOR (1 line)                                                                                                                                                                                                                                                                            | → `CLAUDE/issue-sdlc.md`                                                                                                                                                                                             | resident                                                                                    | Covered in full by issue-sdlc.md.                                                                                                                                                                                                                                                                                                                                           |
| Work happens on `php8.5` (binding, Owner)                              | 159–170   | 12 / 672           | FLOOR                                                                                                                                                                                                                                                                                     | Keep about 4 lines, or create canon `CLAUDE/release-lines.md`                                                                                                                                                        | resident                                                                                    | Root `CLAUDE.md` is the **only** agent-tree home. README.md 518–563 paraphrases it for humans. Comments in `tests/Small/IssueFormsTest.php:16,42` name "CLAUDE.md" as the home of the ruling.                                                                                                                                                                               |
| Working on php-qa-ci from a consuming project's `vendor/` (dogfooding) | 171–205   | 35 / 1 663         | EXTRACT plus a 2-line FLOOR                                                                                                                                                                                                                                                               | `CLAUDE/dogfooding.md` (new)                                                                                                                                                                                         | nav map. Do not rely on a path rule; see §7                                                 | Coupled to `src/PHPStan/Rules/ForbidUnanchoredVendorSubstringCheckRule.php:28` ("this project's own CLAUDE.md, 'dogfooding' section"). The fenced `composer install` block and the prepush pointer go to the new file.                                                                                                                                                      |
| Overview, plus "Real scripting is PHP, not Bash" (owner ruling)        | 206–229   | 24 / 1 403         | Overview: EXTRACT. Ruling: FLOOR, 2 lines                                                                                                                                                                                                                                                 | Ruling canon in a new `CLAUDE/bash-vs-php.md` (or `CLAUDE/architecture.md#…`) with the ShellCheck worked example                                                                                                     | resident floor, plus PATH `phpqaci-shell-scripts`                                           | `docs/tools/shellCheck.md:33` cites 'the "real scripting is PHP" rule in `CLAUDE.md`'; repoint it. The `## Overview` heading trips the daemon's R-INSTRUCTION-LLM-SUMMARY heading pattern, so avoid that word as a heading.                                                                                                                                                 |
| Architecture (Core Components, How It Works)                           | 230–253   | 24 / 3 193         | EXTRACT; partly DUP                                                                                                                                                                                                                                                                       | `CLAUDE/architecture.md` (new)                                                                                                                                                                                       | nav map, plus PATH `phpqaci-pipeline-source`                                                | Partial paraphrase of `docs/pipeline.md:7` and `docs/phpqa-tools.md:3`. The `ToolRegistry`/`ShippedTools`/`PipelineBuilder` roles and the "a new tool extends the public API" rule (→ tool-boundaries.md) are agent-specific.                                                                                                                                               |
| Pipeline Execution Order (Preflight, Phases 1–4, Post-Success, Final)  | 254–368   | 115 / 10 865       | DUP of `docs/pipeline.md` (paraphrase, with CLAUDE.md-only extras). **Stale**, see §3                                                                                                                                                                                                     | `CLAUDE/pipeline.md` (agent depth) **or** point at `docs/pipeline.md` after the extras are merged in                                                                                                                 | nav map, plus PATH `phpqaci-pipeline-source`                                                | The phase roster is derived (R5: ToolRegistry). Extras found only here: turbo-ext.json in PHAR verification; `RepeatUntilSettled` (up to 3 passes); `ProcessTree` / `ext-pcntl` / `ext-posix` rationale; `Pipeline::EXIT_LOCK_CONTENDED`; "Twig Lint not `-t` selectable". `docs/tools/phpstan.md:99` links to the anchor `CLAUDE.md#preflight-phase-configuration--setup`. |
| Configuration System (Cascade, Read-Only/CI, Key Variables, Memory)    | 369–457   | 89 / 8 787         | Cascade: DUP `docs/configuration.md:103-119`. Read-only/CI: **FLOOR** (1–2 lines) plus EXTRACT. Variables table: DUP-ish of `docs/upgrading-to-8.5.md:136-170` (env → builder table). Memory: DUP of `docs/configuration.md:56-71`, where the `withMemoryLimit('8G')` snippet is verbatim | `CLAUDE/configuration.md` (new) for the agent depth. Floor keeps: "CI = interactivity only; to reproduce CI run `QA_READONLY=1 bin/qa`; the working default is writable"                                             | resident floor, plus PATH `phpqaci-config`                                                  | The read-only rule is also stated in `CLAUDE/prepush-verification.md:6-22`, `docs/upgrading-to-8.5.md:500` and `docs/github-actions.md:360`. The table carries 3.5k chars of padding (raw 8 787 vs 5 288 squeezed).                                                                                                                                                         |
| Platform Detection                                                     | 458–469   | 12 / 725           | DUP `docs/platform-detection.md`                                                                                                                                                                                                                                                          | Pointer only                                                                                                                                                                                                         | nav map                                                                                     | Consistent with the code (`ToolRegistry::platformLanes()` exists at :145).                                                                                                                                                                                                                                                                                                  |
| Tool Runner System                                                     | 470–492   | 23 / 1 189         | DUP `docs/phpqa-tools.md:9-20` and `docs/pipeline.md:23`                                                                                                                                                                                                                                  | `CLAUDE/architecture.md`                                                                                                                                                                                             | PATH `phpqaci-pipeline-source`                                                              | "The retry, aggregate and exit-code policies belong to the runner, not the lanes" is an agent invariant worth keeping in architecture.md.                                                                                                                                                                                                                                   |
| PHP 8.5 Compatibility (php8.5 branch)                                  | 493–509   | 17 / 747           | DUP `docs/coding-standards.md:14-28`. The fenced php_cs snippet is a derived copy of `configDefaults/generic/php_cs.php`                                                                                                                                                                  | Pointer only, or delete after verifying it against coding-standards.md                                                                                                                                               | PATH `phpqaci-config` (configDefaults)                                                      | "PHP CS Fixer 3.95+" is a version claim (R5). Check it against phive.xml.                                                                                                                                                                                                                                                                                                   |
| Hook System (Built-in Hooks, Per-Tool Overrides, Claude Code Hooks)    | 510–641   | 132 / 4 829        | Built-in hooks: **DUP verbatim**. The deterministic sweep flags `CLAUDE.md:523-534` ≡ `docs/pipeline.md:29-40`. Per-tool override example: DUP of `docs/upgrading-to-8.5.md#3-…` and `docs/phpqa-tools.md:15`. Claude Code Hooks: DUP of `README.md:403-452` (paraphrase)                 | `CLAUDE/extension-points.md` (new) or fold into `CLAUDE/configuration.md`. Claude Code hooks and the daemon full-QA blocker go to `CLAUDE/claude-code-integration.md` (new)                                          | PATH `phpqaci-config` (qaConfig/), PATH `phpqaci-claude-deploy`                             | `.claude/hooks/README.md` is already named as the hook SSoT. All 6 `php-qa-ci__*.py` hooks exist in git.                                                                                                                                                                                                                                                                    |
| Managed Source                                                         | 642–648   | 7 / 345            | Already a pointer → `CLAUDE/managed-source.md`                                                                                                                                                                                                                                            | Map entry only                                                                                                                                                                                                       | nav map                                                                                     |                                                                                                                                                                                                                                                                                                                                                                             |
| Environment Requirements, Custom PHP Executable                        | 649–673   | 25 / 824           | DUP of `README.md:493-503` and the composer.json `require`                                                                                                                                                                                                                                | `CLAUDE/configuration.md`, or a pointer                                                                                                                                                                              | nav map                                                                                     | "PHP 8.5 or higher" is a derived fact; composer.json `^8.5` is the canon.                                                                                                                                                                                                                                                                                                   |
| Common Customizations                                                  | 674–707   | 34 / 1 001         | DUP of `docs/configuration.md:135-167` (qa.php) plus internal duplication of the "Per-Tool Overrides" section                                                                                                                                                                             | `CLAUDE/configuration.md`                                                                                                                                                                                            | PATH `phpqaci-config`                                                                       | Consumer-facing material; docs/ is its home.                                                                                                                                                                                                                                                                                                                                |
| Overriding Tool Configurations                                         | 708–728   | 21 / 764           | DUP of `docs/configuration.md:103-133` and `docs/coding-standards.md:30-49`                                                                                                                                                                                                               | Pointer; the relative-path warning goes to configuration.md                                                                                                                                                          | PATH `phpqaci-config`                                                                       |                                                                                                                                                                                                                                                                                                                                                                             |
| Tools Reference (27 tool subsections)                                  | 729–1004  | 276 / 24 777       | **DUP**. Every subsection ends with a `Details:` link to `docs/tools/<name>.md` (or `CLAUDE/branch-policy.md`), and the generated Active defences list repeats the same lane → page map                                                                                                   | Delete from the resident file **after** a per-tool check that each fact is in `docs/tools/<name>.md` (the "retiring a duplicate" steps). Unique facts go into the tool page                                          | `rule-doc <identifier>`, the generated defences list, PATH `phpqaci-pipeline-source` (Lane) | The largest single saving (about 6.2k tokens). `CLAUDE/tool-boundaries.md:71` instructs "the tool list in `CLAUDE.md` and `docs/pipeline.md`" be updated for a new tool, so it must change in the same commit. See §3 for the facts at risk.                                                                                                                                |
| Important Notes                                                        | 1005–1012 | 8 / 675            | Mostly DUP. Item 2 is **stale or misleading** (§3)                                                                                                                                                                                                                                        | Delete. Item 5 is covered by the read-only floor                                                                                                                                                                     | —                                                                                           |                                                                                                                                                                                                                                                                                                                                                                             |
| Design Philosophy: Standardized Configuration ("NOT a Tool Proxy")     | 1013–1076 | 64 / 2 881         | FLOOR (2 lines) plus EXTRACT                                                                                                                                                                                                                                                              | `CLAUDE/design-philosophy.md` (new) or `CLAUDE/architecture.md#not-a-tool-proxy`                                                                                                                                     | resident floor                                                                              | No other doc states this ruling. The ✅/❌ lists and the `docker.bash` guidance are consumer-facing; consider a human summary in docs/.                                                                                                                                                                                                                                     |
| `<hooksdaemon>` … `</hooksdaemon>`                                     | 1077–1547 | 471 / 90 500       | **GEN, immovable**                                                                                                                                                                                                                                                                        | —                                                                                                                                                                                                                    | —                                                                                           | Written by `core/claude_md_injector.py`, which targets `workspace_root/"CLAUDE.md"` (a fixed path) and **auto-commits the whole file** on restart. See §7.                                                                                                                                                                                                                  |

---

## 3. Duplication map, contradictions and stale facts

### 3.1 Where each section's facts already live

| CLAUDE.md section                 | Same facts elsewhere                                                                                                                                                                                  | Closeness                                                 |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| Knowledge & Memory                | Nowhere else. The daemon's `R-MARKDOWN-UNTRACKED-MEMORY` enforces it                                                                                                                                  | Canon is here                                             |
| Defence Before Fix                | `CLAUDE/DefenceBeforeFix.md`, `CLAUDE/tool-boundaries.md`, README.md:7-60, the consumer template                                                                                                      | Paraphrase; the summary is compliant                      |
| Segfaults                         | `CLAUDE/segfault-policy.md`                                                                                                                                                                           | Paraphrase, slightly over-detailed                        |
| Releases                          | `CLAUDE/releases.md`, README.md:518-553 (human), `docs/tools/changelog.md`                                                                                                                            | Paraphrase                                                |
| Tools stay current                | `CLAUDE/tool-currency.md:30-48`                                                                                                                                                                       | Paraphrase                                                |
| Issue loop                        | `CLAUDE/issue-sdlc.md`, `.claude/hooks-daemon.yaml` persistent_crons                                                                                                                                  | Paraphrase                                                |
| php8.5 line                       | README.md:518-526 (human paraphrase); `CLAUDE/pr-verification.md` mentions the lines                                                                                                                  | Canon is here                                             |
| Dogfooding                        | `CLAUDE/prepush-verification.md` (the battery)                                                                                                                                                        | Canon is here (the vendor-checkout workflow)              |
| Real scripting is PHP             | `docs/tools/shellCheck.md:28-33` (points back to CLAUDE.md)                                                                                                                                           | Canon is here                                             |
| Architecture                      | `docs/pipeline.md:7`, `docs/phpqa-tools.md:3-20`, `docs/extending-the-pipeline.md`, README.md:62-72                                                                                                   | Paraphrase                                                |
| Pipeline Execution Order          | `docs/pipeline.md:58-138`                                                                                                                                                                             | Close paraphrase; each copy has facts the other lacks     |
| Config cascade                    | `docs/configuration.md:73-119`                                                                                                                                                                        | Paraphrase                                                |
| Read-only / CI                    | `src/Pipeline/Config/EnvironmentReader.php:87-122` (the code, consistent), `docs/pipeline.md:11`, `CLAUDE/prepush-verification.md:6-22`, `docs/upgrading-to-8.5.md:500`, `docs/github-actions.md:360` | Paraphrase, consistent with the code                      |
| Key Configuration Variables table | `docs/upgrading-to-8.5.md:136-170`, `docs/configuration.md:39-71`                                                                                                                                     | Paraphrase; an R5-style roster                            |
| Memory                            | `docs/configuration.md:56-71`, `QaConfigBuilder.php:147` (`'4G'`)                                                                                                                                     | The snippet is verbatim                                   |
| Platform Detection                | `docs/platform-detection.md`                                                                                                                                                                          | Paraphrase                                                |
| Tool Runner                       | `docs/phpqa-tools.md:9-20`, `docs/pipeline.md:23`                                                                                                                                                     | Paraphrase                                                |
| PHP 8.5 compat                    | `docs/coding-standards.md:14-28`, `configDefaults/generic/php_cs.php`                                                                                                                                 | Paraphrase plus a derived snippet                         |
| Built-in hooks                    | `docs/pipeline.md:25-56`                                                                                                                                                                              | **Verbatim code block** (sweep finding `duplicate-block`) |
| Per-tool overrides                | `docs/phpqa-tools.md:11-18`, `docs/upgrading-to-8.5.md` §3                                                                                                                                            | Paraphrase (differing examples)                           |
| Claude Code Hooks                 | README.md:403-452, `.claude/hooks/README.md`, `docs/hooks-daemon-full-qa-blocker.md`                                                                                                                  | Close paraphrase                                          |
| Environment requirements          | README.md:491-503, composer.json                                                                                                                                                                      | Paraphrase                                                |
| Common Customizations, Overriding | `docs/configuration.md:103-167`, `docs/coding-standards.md:30-49`                                                                                                                                     | Paraphrase                                                |
| Tools Reference                   | `docs/tools/*.md` (31 pages), `docs/phpqa-tools.md` (a second parallel index), the generated Active defences region                                                                                   | Three parallel indexes of the same roster                 |
| Design Philosophy                 | Nowhere                                                                                                                                                                                               | Canon is here                                             |

Only one duplicate is caught deterministically. `hooks-daemon docs-qa --sweep --json`, run from
the worktree, reports for `CLAUDE.md` the single `duplicate-block` at `CLAUDE.md:523-534` ≡
`docs/pipeline.md:29-40`. Every other overlap is a paraphrase, which only the semantic
`docs-qa` agent can judge.

### 3.2 Contradictions and stale facts, verified against the code

1. **Phase 3 list omits `deadCode`.** In `CLAUDE.md:333-339`, PHPStan is followed directly by
   PHPArkitect. `ToolRegistry.php:115` registers `deadCode` in `$staticAnalysis` between
   `phpstan` and `phpArkitect`, gated `ToolGateEnum::NotQuick`. `docs/pipeline.md:119` is right.
   The CLAUDE.md "Gates" sentence (line 346) also omits deadCode's NotQuick gate, and
   `docs/pipeline.md:128` has the same omission.
2. **Preflight omits two steps.**
   - It omits the `qaConfig/pipeline.php` / `PipelineConfigLoader` step, which
     `docs/pipeline.md:69` documents. The file `src/Pipeline/Config/PipelineConfigLoader.php`
     exists.
   - Arguments (line 260) omits `--agent-mode`: `ArgumentsParser.php:27` defines
     `AGENT_MODE_OPTION = '--agent-mode'`, and `docs/agent-mode.md` documents it together with
     `PHPQACI_AGENT_MODE`.
   - The env-var table omits `PHPQACI_AGENT_MODE`, `QA_READONLY` and `QA_FAIL_FAST`. The latter
     two appear only in prose.
3. **Phase numbering is incoherent in both copies.** In CLAUDE.md, Phase 2 starts at "03" and
   Phase 3 restarts at "15". In `docs/pipeline.md`, Phase 4 restarts at "20", colliding with
   Phase 3's 20. This is an R5 derived-count failure; the fix is to drop the numbers.
4. **"Important Notes" item 2 is misleading.** At `CLAUDE.md:1008` it says "Project's
   vendor/bin is used — Not php-qa-ci's internal vendor directory". The pipeline actually runs
   tools from `vendor-phar/`. Only `phpunit` and `paratest` come from the project bin dir
   (`PhpunitTool.php:104,121`, `InfectionTool.php:595`), and the active defence
   `phpqaci.binDirTool` forbids anything else. "How It Works" item 4 (line 249) is accurate;
   item 2 contradicts it in spirit.
5. **`docs/phpqa-tools.md` is stale against the code; CLAUDE.md is right here.**
   `docs/phpqa-tools.md:20` and `:189-194` call Yaml Lint a Symfony platform lane. In fact
   `yamlLint` is a shipped lane in the main registry (`ToolRegistry.php:110`), gated on
   `symfony/yaml`, and only `twigLint` is a platform lane (`ToolRegistry.php:152`). Not this
   plan's file, but it belongs in the same SSoT cleanup.
6. **"Phase 2: Linting Tools (validation only)"** (line 295) contradicts `phpStrictTypes`, which
   adds the declaration in a writable run (CLAUDE.md:833 itself says so). `docs/phpqa-tools.md:58`
   repeats "validate code without modifying it".
7. **R5 version and derived claims to verify or drop.** "PHP CS Fixer 3.95+" (line 499); "4G"
   (consistent with `QaConfigBuilder.php:147`); "at most three passes" (`RepeatUntilSettled`);
   "half the CPU threads"; "v3.67.0" for the daemon blocker. Each restates its source.
8. **Verified OK.** Every relative markdown link in lines 1–1076 resolves. The named symbols
   exist: `EnvironmentReader::isReadOnly()` (:108), `ToolRegistry::platformLanes()` (:145),
   `Pipeline::EXIT_LOCK_CONTENDED = 75` (:37), `ShippedTools::all()` (:48), `RepeatUntilSettled`,
   `AggregateReport`. `CLAUDECODE=1` → CI (`EnvironmentReader.php:97`). All 6
   `php-qa-ci__*.py` hooks are present.

---

## 4. Proposed path-rule globs, checked against `git ls-files`

Every rule follows R7a: a pointer, two imperative lines and a link into `CLAUDE/`. New names
carry a `phpqaci-` prefix so they cannot collide with the 8 daemon-owned files. Counts are of
tracked files.

| Rule file                    | `paths:` globs                                                                                                                                                                            | Tracked files matched                         | Points to                                                                                                 |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `phpqaci-shell-scripts.md`   | `**/*.bash`, `**/*.sh`, `bin/**`, `scripts/**`, `ci.bash`                                                                                                                                 | 66 (36 by extension; none under tests/assets) | bash-is-invocation canon (new `CLAUDE/bash-vs-php.md` or `architecture.md#…`), `docs/tools/shellCheck.md` |
| `phpqaci-pipeline-source.md` | `src/Pipeline/**`, `docs/tools/**`                                                                                                                                                        | 181 (150 + 31)                                | `CLAUDE/architecture.md`, `CLAUDE/tool-boundaries.md`, `CLAUDE/pipeline.md`                               |
| `phpqaci-config.md`          | `configDefaults/**`, `qaConfig/**`, `templates/**`                                                                                                                                        | 40 (19 + 11 + 10)                             | `CLAUDE/configuration.md` (cascade, relative-path warning, hooks and overrides)                           |
| `phpqaci-phpstan-rules.md`   | `src/PHPStan/**`, `rules-default.neon`, `rules-optional.neon`, `rules-optional-symfony.neon`, `docs/phpstan-rules/**`                                                                     | 118                                           | `CLAUDE/DefenceBeforeFix.md`                                                                              |
| `phpqaci-claude-deploy.md`   | `.claude/hooks/php-qa-ci__*`, `scripts/deploy-skills.bash`, `scripts/lib/deploy-*`, `src/HooksDaemon/**`, `src/ComposerPlugin/**`                                                         | 28                                            | `CLAUDE/claude-code-integration.md` (new), `docs/hooks-daemon-full-qa-blocker.md`                         |
| `phpqaci-tool-delivery.md`   | `phive.xml`, `vendor-phar/**`, `vendor-bin/**`, `build/**`, `scripts/build-phar.bash`, `scripts/tool-install.bash`, `composer.json`, `composer.lock`, `.github/workflows/update-deps.yml` | 35                                            | `CLAUDE/tool-currency.md`, PHAR verification in `CLAUDE/pipeline.md`                                      |
| `phpqaci-releases.md`        | `CHANGELOG.md`, `src/Changelog/**`, `.github/workflows/*`                                                                                                                                 | 32 (1 + 27 + 4)                               | `CLAUDE/releases.md`                                                                                      |

Population facts the globs depend on:

- **Extensionless shell scripts are covered only by `bin/**`.** There are five of them:
  `bin/composer-require-checker`, `bin/infection`, `bin/php-cs-fixer`, `bin/phpstan` and
  `bin/phpstan-rule`, all `#!/usr/bin/env bash`.
- **`bin/**` (29 files) also covers the PHP entry points** (`#!/usr/bin/env php`, plus
  `bin/bootstrap.php`). That is intended: "thin `bin/` entry point, logic in `src/`" applies to
  them too.
- **`scripts/` holds two non-bash files**: `scripts/issue-monitor` (php) and
  `scripts/parse-junit-logs.py`.
- **`scripts/lib/*.inc.bash` files have no shebang.** Their first line is a comment, so only the
  extension or `scripts/**` catches them. The same is true of `build/rector/prepare.bash`.
- **`**/*.sh` also matches daemon-owned scripts** under `.claude/skills/{docs-qa,hooks-daemon}/scripts/`
  and `.claude/{init,provision}.sh`. The pointer is harmless there, but the glob could be
  narrowed to exclude `.claude/**` if preferred.
- **Do not glob `.claude/hooks/**` whole.** 32 of its 40 files are daemon-owned dispatcher
  stubs.
- **Do not use `src/**` (319 files) or `tests/**` (625, of which 306 are `tests/assets`) as
  triggers.** They are too broad and would fire on almost every task.

Nothing extra is needed for the location rules: a new `.claude/rules/*.md` file is allowed by
default. `markdown_organization.py:378-409` (`is_adhoc_instruction_file`) accepts any `.md`
under `.claude/rules/` at any depth, before the location rules are consulted.

`extra_allowed_markdown_paths` is ADDITIVE. It rescues paths that the base check would block
(`markdown_organization.py:1218-1251`). `allowed_markdown_paths` is the option that OVERRIDES all
built-ins, and this project leaves it unset (`.claude/hooks-daemon.yaml:238-259`). New
`CLAUDE/*.md` files are allowed by the default `^CLAUDE/.*\.md$` rule.

`documentation.qa` is entirely unconfigured (`.claude/hooks-daemon.yaml:509-513`), so every
check runs at its advisory default. There is no `generated_docs` manifest entry for the
active-defences region (R10 asks for one), no `registered_module_docs` and no
`grandfather_allowlist`.

---

## 5. Couplings: code, tests and tooling that read or name CLAUDE.md

**Hard couplings.** A test or tool fails if the content moves.

| Coupling                                                                                                           | What it requires                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Small/AgentContextIsCurrentTest.php:33-41`                                                                  | `CLAUDE.md` at the repo root contains exactly one `<!-- phpqaci-active-defences:start -->…end` region, byte-identical to `ActiveDefencesSummary::render()` (heading `## php-qa-ci — Active defences`)                                                                                                                                                                                              |
| `tests/Small/AgentContextIsCurrentTest.php:44-47`                                                                  | `templates/root-CLAUDE-phpqaci-block.md.template` has the region. This is the consumer template, not this repo's file                                                                                                                                                                                                                                                                              |
| `tests/Small/UpdateDepsRegeneratesLockDerivedFilesTest.php:18,45,58-62`                                            | `.github/workflows/update-deps.yml` runs `php bin/rules . --write-agent-summary=CLAUDE.md` inside the lock-update window                                                                                                                                                                                                                                                                           |
| `.github/workflows/update-deps.yml:90-95`                                                                          | Regenerates the region in `CLAUDE.md` daily                                                                                                                                                                                                                                                                                                                                                        |
| `bin/rules:70-88`, `src/AgentContext/AgentContextRegion.php`, `src/AgentContext/ActiveDefencesSummary.php`         | The generator. **The target file is a CLI argument** (`--write-agent-summary=<file>`) and the code is not tied to `CLAUDE.md`. It refuses (exit 1) when the file lacks exactly one well-formed region. It replaces only the region; the rest of the file is untouched                                                                                                                              |
| `scripts/lib/deploy-claude-block.inc.bash:16,35`                                                                   | Consumer side only: writes the template's region into the consumer's `CLAUDE.md`. Unaffected by this plan                                                                                                                                                                                                                                                                                          |
| `src/Pipeline/Config/QaConfigBuilder.php:43,309-310` and `tests/Small/Pipeline/Config/QaConfigBuilderTest.php:666` | The `markdownFormat` lane formats `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/` and `CLAUDE/` by default. New `CLAUDE/*.md` files are formatted automatically. **`.claude/rules/*.md` are NOT** in the default set, so new rule files escape that lane; the daemon's own edit-time formatting still applies                                                                                    |
| Hooks-daemon `core/claude_md_injector.py:279-401`                                                                  | Writes `<hooksdaemon>` into `workspace_root/CLAUDE.md` (fixed path) and **auto-commits the whole file** on daemon restart. A commit containing edits outside the block gets the message `Auto: hooks daemon committed CLAUDE.md …` (:133-142). It skips linked worktrees (:326), so a restart cannot sweep in-progress edits in this worktree, but a restart in `/workspace` can sweep edits there |

**Soft couplings.** Prose or pointers that name a CLAUDE.md section. They go stale silently.

| Location                                                            | Text                                                                                                                                                                                                                                                                              |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/tools/phpstan.md:99`                                          | Links `../../CLAUDE.md#preflight-phase-configuration--setup`. **This anchor breaks if Preflight moves.** `LinksChecker` (the markdownLinks lane) checks README.md and `docs/`; the daemon's `pointer-resolves` check is anchor-aware. Repoint to `docs/pipeline.md#2-preparation` |
| `docs/tools/shellCheck.md:33`                                       | 'per the "real scripting is PHP" rule in `CLAUDE.md`'                                                                                                                                                                                                                             |
| `CLAUDE/tool-boundaries.md:70-72`                                   | Procedure step: register a new tool in "the tool list in `CLAUDE.md` and `docs/pipeline.md`". Must change together with the removal of the Tools Reference and phase lists                                                                                                        |
| `CLAUDE/DefenceBeforeFix.md:285-287`                                | Describes `--write-agent-summary=CLAUDE.md` and `AgentContextIsCurrentTest`. Still true if the region stays                                                                                                                                                                       |
| `CLAUDE/tool-currency.md:20,80`                                     | "It regenerates the active-defences region of `CLAUDE.md`"                                                                                                                                                                                                                        |
| `docs/phpstan-rules/README.md:74,90-94`                             | Documents `--write-agent-summary=CLAUDE.md`                                                                                                                                                                                                                                       |
| `src/PHPStan/Rules/ForbidUnanchoredVendorSubstringCheckRule.php:28` | Docblock: "see this project's own CLAUDE.md, 'dogfooding' section"                                                                                                                                                                                                                |
| `src/PHPStan/Rules/RequireDeclareStrictTypesRule.php:18`            | "mandatory per CLAUDE.md convention" (no specific section)                                                                                                                                                                                                                        |
| `tests/Small/IssueFormsTest.php:16,42`                              | Comments: the php8.4 support ruling is "in CLAUDE.md"                                                                                                                                                                                                                             |
| `.claude/agents/php-qa-ci-plan-fact-checker.md:29`                  | "Agent knowledge lives in `CLAUDE.md`, `CLAUDE/*.md` and `.claude/rules/*.md`". Still true                                                                                                                                                                                        |
| `.claude/agents/php-qa-ci_docs-conflict-checker.md`                 | Reads `CLAUDE.md` first. This is shipped to consumers and is generic                                                                                                                                                                                                              |
| `.claude/hooks/README.md:419`                                       | "PHP-QA-CI main docs: `CLAUDE.md` and `docs/`"                                                                                                                                                                                                                                    |

**Not coupled**, checked:

- `docsProse` covers README.md and `docs/` only.
- `markdownLinks` covers README.md and `docs/` (`LinksChecker.php:51,62`); it does not check
  `CLAUDE.md` or `CLAUDE/`.
- `tests/Small/Markdown/DocumentSelfReferenceScannerTest.php:136` writes a fixture CLAUDE.md in
  a temporary project.
- `tests/Large/DeploySkills/*` check the consumer `CLAUDE.md`.
- `DefenceBeforeFixDeclarationTest` reads `CLAUDE/Plan`, not `CLAUDE.md`.
- No test asserts any CLAUDE.md heading other than the generated region.
- No `.gitattributes` and no composer `archive.exclude` exist, so `CLAUDE/` ships in the dist,
  and links from a vendored checkout resolve.

**Can the active-defences region move?** The mechanics say yes: the target is a CLI argument.
The requirements say no. TOOLING-SPEC 7.2 (`remote-docs/defence-before-fix.github.io/TOOLING-SPEC.md:547-550`)
says the summary goes "into the file the project's Agents already load … in a delimited region
marked as generated". `composer.json` `extra.defence-before-fix` declares toolchain 0.2.0 at
project level with `known-gaps: []`. Moving the region to a lazily-loaded file would therefore
open an undeclared 7.2 gap, which is an Owner decision. An always-loaded `.claude/rules/` file
with no `paths:` would satisfy 7.2. But it would need three call sites changed (the test, the
workflow, and the test that asserts the workflow, plus the docs that describe it), an R10
manifest entry, and it would trip R7a's pointer shape. It also saves no tokens, because the
region stays resident wherever it lives. **Recommendation: leave it in `CLAUDE.md`.**

---

## 6. Draft target skeleton for the new root CLAUDE.md

The aim is the smallest editable surface that keeps every binding rule resident. The floor
carries an R4a marker; `<!-- dbf-safety-floor -->` is a proposed spelling, which would be
recorded in `CLAUDE/DocumentationStrategy.md`.

```text
# php-qa-ci — agent context                                    (~3 lines)
  What this repo is (the PHP reference toolchain for Defence Before Fix); agent docs in CLAUDE/,
  human docs in docs/; this file is the binding floor plus a map.

## Binding rules (resident safety floor)                        (~45–55 lines)
  <!-- R4a marker -->  one bullet each, 1–4 lines, each ending in its canonical link:
  - Knowledge & memory: no harness memory; knowledge in CLAUDE/*.md, work in CLAUDE/Plan/
  - Defence Before Fix: found a defect, build the detector first; new check = identifier + page
    + bin/rules; suppression/baseline/narrowing = Owner decision → DefenceBeforeFix.md, tool-boundaries.md
  - Segfault = halt → debug → file → defend → segfault-policy.md
  - Releases from the changelog: entry under ## Unreleased or a `Changelog: none — <reason>` trailer;
    never tag by hand → releases.md
  - Tools stay current: at session start/end check update PR, update-deps-failure and unreplied issues;
    green update PR merges after fresh sub-agent verification → tool-currency.md
  - GitHub issue loop (gh-sdlc session) → issue-sdlc.md
  - Work on php8.5; php8.4 = reported fixes only (#36 the exception); php8.3 dead   [canon stays here
    unless CLAUDE/release-lines.md is created]
  - Pre-push battery is mandatory; pushing php8.5 deploys to production → prepush-verification.md;
    agent-merged PRs need a separate verifying sub-agent → pr-verification.md
  - Bash is for invocation, PHP is for logic → CLAUDE/bash-vs-php.md
  - The QA pipeline is not a tool proxy: no arbitrary tool flags; config lives in qaConfig/ → design-philosophy
  - CI ≠ read-only: CI=interactivity; reproduce CI with QA_READONLY=1 bin/qa; working default is writable
  - Dogfooding from vendor/lts/php-qa-ci: edit and commit there, and pass this repo's own battery → dogfooding.md

## Where to look                                                 (~20–25 lines)
  Task → doc, plain links, no per-file prose index:
  architecture / adding a tool → CLAUDE/architecture.md, tool-boundaries.md
  pipeline order, preflight, gates → CLAUDE/pipeline.md (or docs/pipeline.md)
  configuration, qa.php, hooks, overrides, env vars → CLAUDE/configuration.md
  a tool's behaviour → `rule-doc <identifier>` / docs/tools/<name>.md; roster = `bin/qa -h`
  Claude Code hooks, deploy-skills, daemon blocker → CLAUDE/claude-code-integration.md
  QA run → fix cycle → qa-orchestration.md; branches → branch-policy.md; plans → PlanWorkflow.md,
  PlanJournalling.md; worktrees → Worktree.md; docs SSoT → DocumentationStrategy.md;
  managed source → managed-source.md

<!-- phpqaci-active-defences:start --> … <!-- …:end -->          (generated, 83 lines, unchanged)

<hooksdaemon> … </hooksdaemon>                                  (generated, 471 lines, unchanged)
```

Estimated editable surface: about 75–85 lines, 1 000–1 300 words, 8–9k chars, **about 2.0–2.3k
tokens**, down from 17.5k (−87%). The whole file would be about 27k tokens raw (about 15.5k
squeezed), against 42.6k now. The rest is the two generated regions.

New `CLAUDE/*.md` files would take the relocated content verbatim first, then be reconciled
against the docs/ copies:

- `architecture.md`
- `pipeline.md`
- `configuration.md`
- `claude-code-integration.md`
- `dogfooding.md`
- `bash-vs-php.md`
- `design-philosophy.md`, optionally merged into architecture.md
- `release-lines.md` and `knowledge-policy.md`, only if the Owner wants the canon moved out of
  root

---

## 7. Risks and open questions

01. **The SSoT direction between the agent tree and `docs/` is unresolved.**
    - R3 says the agent tree owns the depth and `docs/` summarises. Today the depth for pipeline
      and configuration behaviour lives in `docs/`: it ships to consumers and holds the
      `rule-doc` targets.
    - `docs/tools/*.md` and `docs/phpstan-rules/*.md` must stay where they are, because DBF
      requires a page per identifier resolvable offline and `rule-doc` resolves to those paths.
    - Option A: relocate the CLAUDE.md sections verbatim into new `CLAUDE/*.md` files and later
      thin `docs/` to summary-plus-link. That is a bigger change and touches the consumer-facing
      docs.
    - Option B: declare in `CLAUDE/DocumentationStrategy.md` that `docs/tools/`,
      `docs/phpstan-rules/` and consumer-behaviour pages are canonical for consumer-visible
      behaviour. Agent-only internals (architecture, invariants, contributor procedures) then go
      to `CLAUDE/`, and the duplicates in CLAUDE.md are retired by "verify present, then thin".
    - **This needs an Owner ruling before extraction starts.**
02. **"Relocate verbatim, never delete" versus "strict SSoT".** About 60% of the editable text
    (the Tools Reference, the pipeline order, the hooks code) is already in `docs/`. Moving it
    verbatim into `CLAUDE/` would create new verbatim duplicates, which the `duplicate-block`
    sweep would flag. The core doc's retiring procedure (verify present, then thin, in one
    commit) is the knowledge-preserving path. It still needs a per-fact check, because the
    CLAUDE.md-only extras listed in §2 must land somewhere first.
03. **Path rules are invisible when dogfooding.** Claude Code loads `.claude/rules/` only from
    the session's project root. An agent working on `vendor/lts/php-qa-ci/` inside a consumer
    lazily loads `vendor/lts/php-qa-ci/CLAUDE.md`, but never php-qa-ci's `.claude/rules/`.
    Every pointer a path rule carries must therefore also be reachable from the root
    navigation map. Path rules can only be an accelerator.
04. **R7a caps rule files at 15 body lines, pointers only.** Planners must not plan to "move a
    section into a path rule". That would be blocked, worse-only, by `rules-file-shape`.
05. **The daemon auto-commits CLAUDE.md.**
    - A daemon restart in the main checkout commits a dirty `CLAUDE.md` wholesale. Its
      regeneration also re-runs mdformat over the file (`markdownFormat` / the injector's format
      memory).
    - Edit CLAUDE.md only inside the worktree; the injector skips linked worktrees. Expect a
      regenerated `<hooksdaemon>` block to conflict at merge, as happened between `/workspace`
      and this worktree, whose blocks already differ.
    - The generated region must stay byte-identical to `render()`. Do not let a hand reflow
      touch lines 40–122. Regenerate with `php bin/rules . --write-agent-summary=CLAUDE.md` after
      editing.
06. **Instruction-content validators.** The daemon's `validate_instruction_content` blocks
    headings such as `## Overview` and `## Summary`, ISO dates, and ✅ followed by status words
    in CLAUDE.md and README.md. The current file has `## Overview` (line 206) and ✅/❌ lists
    (lines 1021-1073). They are grandfathered by the worse-only rule, but **a rewrite counts as
    new content**, so drop those shapes.
07. **Anchor links.** `docs/tools/phpstan.md:99` →
    `CLAUDE.md#preflight-phase-configuration--setup` breaks when Preflight moves. Fix it in the
    same commit, or the daemon's `pointer-resolves` check blocks the edit that broke it.
08. **The roster lives in three or four places.** CLAUDE.md phases and Tools Reference,
    `docs/pipeline.md`, `docs/phpqa-tools.md` and the generated region all list it.
    `CLAUDE/tool-boundaries.md:70-72` makes "update the CLAUDE.md tool list" part of the
    new-tool procedure. Rewrite that step to drop CLAUDE.md, or the procedure points at
    something that no longer exists. Consider also whether `docs/phpqa-tools.md` should be
    retired in favour of `docs/pipeline.md` plus `docs/tools/`. That is out of scope here, but
    it fixes the stale Yaml Lint text.
09. **Canon that exists only in root CLAUDE.md.** These rulings have no other agent-tree home:
    the Knowledge & Memory policy, the php8.5/8.4/8.3 line policy, the dogfooding workflow, "Bash
    is invocation, PHP is logic" and "not a tool proxy". Condensing them without first creating a
    home would lose text. Each needs either a new `CLAUDE/*.md` home (then a floor plus link) or
    an explicit decision that root CLAUDE.md stays canonical for it.
10. **Stale facts to fix while relocating, not to copy.** See §3.2 items 1–4 and 6: deadCode
    missing from Phase 3, preflight missing `pipeline.php` and `--agent-mode`, broken
    numbering, the misleading "vendor/bin is used" note, and "validation only".
11. **The `<hooksdaemon>` block (about 22.6k raw tokens) is now the dominant always-loaded
    cost.** It is out of this plan's editable scope. If the Owner wants it smaller, the only
    route is the handler set, which is a separate daemon-config review. The mdformat column
    padding alone roughly doubles its character count. That is daemon behaviour, worth an
    upstream report, not a local edit.
12. **R10.** The active-defences region is generated but undeclared in
    `documentation.qa.generated_docs`. Add a manifest entry (glob `CLAUDE.md`? region-level
    support is unknown) or accept the advisory. **Open:** can the daemon's manifest express a
    region rather than a whole file?
