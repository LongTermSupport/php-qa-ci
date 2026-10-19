Verified against the official Claude Code docs. The memory page was read in full. The skills and plugin pages were read through a summarizing fetch, so their wording is paraphrased-quoted; re-check exact sentences before quoting them verbatim in the restructure.

1. **Root CLAUDE.md timing.** Root CLAUDE.md and CLAUDE.local.md load at launch from the cwd and every directory above it, and the content is re-injected after `/compact`. The docs do not say it is re-sent every turn. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#how-claude-md-files-load and https://code.claude.com/docs/en/memory.md#instructions-seem-lost-after-compact

2. **Root size guidance.** "Target under 200 lines per CLAUDE.md file." A warning appears at startup and in `/status` when an instruction file is over the recommended length, and also when files that are each under it add up past a combined limit. Hard skip: a CLAUDE.md over 4 MiB is skipped. The 200-line/25KB figure applies only to auto-memory `MEMORY.md`, not to CLAUDE.md. Confidence: documented (the combined-limit number is not stated). Source: https://code.claude.com/docs/en/memory.md#write-effective-instructions, https://code.claude.com/docs/en/memory.md#how-it-works-auto-memory (the 4 MiB and MEMORY.md-only statement), and https://code.claude.com/docs/en/memory.md#my-claudemd-is-too-large

3. **Nested CLAUDE.md.** Subdirectory CLAUDE.md files under the cwd are lazy: loaded once Claude reads, writes or edits a file in that subdirectory (a `cat` or `head` of one file counts as a read). Ancestors above the cwd load eagerly at launch. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#how-claude-md-files-load

4. **@-import syntax.** `@path/to/file`, bare, outside backticks. Relative paths resolve relative to the importing file, not the cwd. Max recursion depth is four hops. Spaces are escaped with a backslash, and quoted paths are not imported. Imports inside Markdown code spans and fenced code blocks are skipped; backticks keep `` `@README` `` literal. Imports do not reduce context cost, since imported files load at launch. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#import-additional-files

5. **Whether `[@text](path)` counts as an import.** Not documented. The docs only describe bare `@path` and backtick-protected text. Confidence: undocumented. Source: same section as item 4.

6. **Path-scoped rules, frontmatter `paths:`.** Rules in `.claude/rules/**/*.md` (recursive) with `paths:` load only when Claude uses Read, Write or Edit on a matching file, or views one with a single-file Bash read such as `cat` or `head`. Brace expansion is supported (`src/**/*.{ts,tsx}`), with a budget of 1,000 expanded patterns and 4 MiB per rule. A pattern with an unescaped `[` that is not a valid bracket expression matches nothing. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#path-specific-rules

7. **Whether `paths:` globs are relative to the project root.** The examples (`src/api/**/*.ts`) imply project-root-relative matching, but the docs do not state the anchor explicitly. Confidence: inferred. Source: https://code.claude.com/docs/en/memory.md#path-specific-rules

8. **Rules without `paths:`.** Loaded unconditionally at launch, with the same priority as `.claude/CLAUDE.md`. User rules in `~/.claude/rules/` load before project rules. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#set-up-rules and https://code.claude.com/docs/en/memory.md#user-level-rules

9. **`description:` in rules.** Not meaningful. The docs say `paths` is the only field Claude Code reads from a rule, and any other field is ignored without error. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#rules-frontmatter-reference

10. **@-imports inside rules files.** Not documented. Confidence: undocumented. Source: no section found in https://code.claude.com/docs/en/memory.md.

11. **Mid-session pickup of new or edited rules.** Not documented for rules. The hot-reload behavior is documented only for skill directories. Confidence: undocumented. Source for the skills contrast: https://code.claude.com/docs/en/skills.md

12. **Skills: what is resident every turn.** Only the skill descriptions (plus `when_to_use`) are in context. The SKILL.md body loads when invoked, and then stays in the conversation as one message. Confidence: documented. Source: https://code.claude.com/docs/en/skills.md (frontmatter and "How skills load" sections)

13. **Skill description limits.** The combined `description` and `when_to_use` is truncated at 1,536 characters in the skill listing. The docs do not give a separate cap for `description` alone. Compaction re-attach budget is 25,000 tokens total, 5,000 per skill. Confidence: documented. Source: https://code.claude.com/docs/en/skills.md

14. **Recommended SKILL.md length.** "Keep SKILL.md under 500 lines. Move detailed reference material to separate files." Confidence: documented. Source: https://code.claude.com/docs/en/skills.md

15. **Skill auto-invocation.** Claude decides from the description. `disable-model-invocation: true` stops Claude from invoking the skill and hides its description from context. `user-invocable: false` only hides it from the `/` menu and does not block Claude's Skill-tool invocation. Confidence: documented. Source: https://code.claude.com/docs/en/skills.md

16. **Skill hot-reload.** Changes under `~/.claude/skills/`, `.claude/skills/` and `--add-dir` skill directories are picked up mid-session. A brand-new top-level skills directory needs `/reload-skills`. Nested `.claude/skills/` load the first time a file in that subdirectory is touched. Confidence: documented. Source: https://code.claude.com/docs/en/skills.md

17. **Disabling auto memory.** Settings key `autoMemoryEnabled` (set via `/memory` toggle, which writes to `~/.claude/settings.json`, or per project in settings). Env var `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`. The docs present auto memory and CLAUDE.md as separate systems, and nothing says the switch affects CLAUDE.md loading. Confidence: documented for the key and env var. The "does not affect CLAUDE.md" part is inferred from the separation. Source: https://code.claude.com/docs/en/memory.md#enable-or-disable-auto-memory and https://code.claude.com/docs/en/memory.md#claude-md-vs-auto-memory

18. **Project-scope plugin in `.claude/plugins/<name>/`.** Not loaded. The docs state: "Claude Code doesn't scan a project's `.claude/plugins/` directory." Project plugins are shared via `enabledPlugins` in `.claude/settings.json`, or by placing a plugin directory under `.claude/skills/`. A skills-directory plugin needs a `.claude-plugin/plugin.json` manifest. Nothing documented says a bare `.lsp.json` with no manifest loads. Treat the LSP-exclude workaround as likely not loaded, and verify with `/plugin` (Errors tab) or `claude --debug`. Confidence: documented for the scan rule; inferred for the no-manifest case. Source: https://code.claude.com/docs/en/plugins/loading.md#plugins-shared-through-a-repository and https://code.claude.com/docs/en/plugins/loading.md#find-where-a-plugin-came-from

19. **`.lsp.json` format and location.** The code-intelligence page says a plugin's `.lsp.json` names the server command and file extensions, and that LSP servers are defined as plugin components. The exact file location and schema are on the components page, which I did not fetch. Confidence: documented for the plugin association; the schema is not verified here. Source: https://code.claude.com/docs/en/plugins/code-intelligence.md#add-a-language-without-an-official-plugin

20. **HTML comments.** Block-level `<!-- ... -->` comments in CLAUDE.md are stripped before injection, so they cost no context tokens. Comments inside fenced code blocks are preserved. Opening the file with the Read tool shows them. The docs say "block-level", so inline comments mid-paragraph are not explicitly covered. Confidence: documented for block-level. Source: https://code.claude.com/docs/en/memory.md#how-claude-md-files-load

21. **Post-compaction behavior.** Root CLAUDE.md is re-read from disk and re-injected after `/compact`. Nested CLAUDE.md and `paths:` rules reload on demand. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#instructions-seem-lost-after-compact

22. **Restructure tooling.** `/doctor prompt-audit` reports stale or conflicting instructions (no edits until asked). `/context` lists loaded memory files at launch. The `InstructionsLoaded` hook logs which CLAUDE.md and rules files load and why, which is useful for verifying lazy loading. Confidence: documented. Source: https://code.claude.com/docs/en/memory.md#audit-your-instruction-files and https://code.claude.com/docs/en/memory.md#claude-isnt-following-my-claudemd

Sources:
- https://code.claude.com/docs/en/memory.md
- https://code.claude.com/docs/en/skills.md
- https://code.claude.com/docs/en/plugins/loading.md
- https://code.claude.com/docs/en/plugins/code-intelligence.md