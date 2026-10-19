<!-- hooks-daemon-issue-report
daemon_version: 3.70.0
generated_at: 2026-10-19
body_sha256: 219510010e119373f2807714bc63f86324299a0fda3124ee28f38e757ae53cc7
-->

## The injected hooksdaemon CLAUDE.md block costs about 22.6k resident tokens, most of it mdformat column padding; some rows also break on an unescaped pipe

|                |                             |
| -------------- | --------------------------- |
| Daemon version | 3.70.0                      |
| Install mode   | normal                      |
| Platform       | Linux x86_64, Python 3.11.2 |
| Handler        | _not stated_                |

## Expected

The handler-guidance block that the daemon injects into a project's root CLAUDE.md stays compact, because it is resident on every turn of every session (and, for a library, in every consumer session that reads a vendored file). A cell containing a literal pipe renders intact. Requested: (a) a compact rendering of the rules section that the formatter leaves alone: a list (one line per rule: ID, blocked, fix) or a table excluded from padding, with explain-rule carrying the Why; (b) escape a literal pipe inside table cells; (c) a documentation option that declares a human-tree subtree canonical reference documentation (for a library whose docs/ pages ship and are resolved by a rule-doc tool), so human-docs.md's 'point into the agent tree' advice is not given there; (d) region-level declaration in documentation.qa.generated_docs, so a generated region inside a hand-written file can be declared without flagging every hand edit of the file.

## Observed

1. In a project whose hand-written CLAUDE.md is about 1.5k tokens, the hooksdaemon block is 90,374 chars, 85% of the file. Its 130 rule-table rows are 79,300 chars raw against 32,468 chars with the column padding squeezed to one space: mdformat pads every cell to the widest cell in its column, so the longest row sets the width for all 130. 2. Rows whose Blocked/Fix text contains a literal pipe inside a code span (R-CURL-PIPE-SHELL, R-PIPE-TO-TAIL, R-PIPE-TO-HEAD, R-ERROR-HIDING, R-BASH-SAFE-MODE-PRELUDE-MISSING, R-VERIFICATION-RESULT-NOT-CONSUMED) are split at the pipe, so their Why/Fix columns show fragments such as 'wget ...' and 'tail\`'. 3. The longest rows (after squeezing) are R-LSP-CONFIG-EXCLUDE, R-CRON-SUBAGENT-STOP-DECLARED, R-GIT-PUSH-DELETE-REMOTE, R-CRON-STOP-DECLARED, R-USAGE-PAUSE-PROMPT and R-STOP-IDLE-WITH-WORKABLE-ISSUE, each about 400 chars.

**Checks run when this report was generated:**

- Installed 3.70.0 is the newest release; nothing to compare.
- `src/claude_code_hooks_daemon/core/claude_md_injector.py:112` resolves in the installed version (911 lines).
- No handler named. That is fine — a report can be about the CLI, the config loader, the installer or the supervisor.

## Reproduction

Restart the daemon in a project with the default handler set, then measure the injected block in CLAUDE.md from its opening tag to its closing tag: total chars, and the sum of the rule-row lengths (lines starting with a pipe and R-) before and after replacing runs of two or more spaces with one. Search the same rows for cells that end in a lone backtick.

## Configuration ruled out

- `handlers.post_tool_use.markdown_table_formatter` — Disabling it does not help: the injector runs the shared formatter over the whole file itself (claude_md_injector.py:473, \_format_or_reuse), so the padding is produced on every restart regardless; the four-column table is declared at claude_md_injector.py:112.
- `disabling individual handlers` — It removes the protection, and the padding cost follows the widest cell per column, not the number of rules.

## Source read

src/claude_code_hooks_daemon/core/claude_md_injector.py:112
