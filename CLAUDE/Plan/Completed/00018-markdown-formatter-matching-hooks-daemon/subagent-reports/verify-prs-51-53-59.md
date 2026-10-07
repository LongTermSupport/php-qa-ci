# Verification: PRs #51, #53, #59

Procedure: `CLAUDE/pr-verification.md` as on `origin/chore/agent-pr-verification` (31f309a).
Verifier: fresh read-only sub-agent. Base read: `origin/php8.5` at 385b01a.
Reproduced = I ran it; Read = from the diff/source only.

Heads covered:

| PR  | Branch                                    | Head verified | Note                                                                                            |
| --- | ----------------------------------------- | ------------- | ----------------------------------------------------------------------------------------------- |
| #51 | `feature/plan-00018-markdown-format-lane` | 7516ef6       |                                                                                                 |
| #53 | `chore/agent-pr-verification`             | 31f309a       |                                                                                                 |
| #59 | `chore/plan-00019-agent-pr-merge-gate`    | aaf7dad       | Pushed after the brief (brief said eea8d00); aaf7dad adds one journal entry only, read in full. |

## Repository settings (live, read through the agent account)

- GraphQL `refUpdateRule`: `php8.5` requiredApprovingReviewCount 0, required checks `["QA Pipeline"]`;
  `php8.4` requiredApprovingReviewCount 0, checks `["QA Pipeline", "ShellCheck (severity=warning)"]`.
- `rules/branches/php8.5`: ruleset 18472656 (`protect`: deletion, non_fast_forward, pull_request with
  0 approvals, `require_extra_approval_for_unattributed_changes: true`, merge methods merge/rebase) and
  ruleset 23328934 (deletion, non_fast_forward). `rules/branches/php8.4`: none.
- All three PRs: `reviewDecision` empty, `mergeable` MERGEABLE.

## CI

See the "CI final state" section at the end.

## #51 — Plan 00018, markdownFormat lane

### Scope

Diff vs php8.5 since #49 merged: the lane (`src/Pipeline/Lane/MarkdownFormatTool.php`), the locator
(`src/HooksDaemon/HooksDaemonCliLocator.php`), the builder/DTO setting, registry + ShippedTools wiring,
tests, docs page, CLAUDE.md / docs/pipeline.md / docs/upgrading-to-8.5.md / docs/phpstan-rules/README.md
rows, CHANGELOG Unreleased › Added entry, Plan 00018 records. Also whitespace-only daemon reformatting
of Plan 00017 journal headings and Plan 00010 DECISIONS/REAUDIT (the `1656bfc` style commit the body
names), and CLAUDE.md/docs/pipeline.md now list `twigCsFixer` as a shipped Phase-1 lane rather than a
Symfony platform lane, which corrects a statement that contradicted CLAUDE.md's own Platform section.
No debug output, secrets or lock-file edits. Since #49 merged, the branch carries only its own changes.

### Project rules

- No new suppression, baseline or ignoreErrors entry.
- DBF ordering: red `2c4f183` before green `eca6370`. A new lane, so it has an identifier
  (`phpqaci.markdownFormat`), a page and a `bin/rules`/agent-summary line.
- CHANGELOG entry present. Branch name `feature/` is allowed.

### Correctness

Reproduced in a throwaway worktree at 7516ef6 (`composer install`, then single test files):
MarkdownFormatToolTest 10/10, HooksDaemonCliLocatorTest 4/4, ToolRegistryCharacterisationTest 89/89,
InProcessLanesTest 13/13, PipelineBuilderTest 14/14, ShippedToolLocatorTest 5/5, tests/Small/Pipeline/Runner
42/42. (`PipelineTest.php` run alone errors on `AlwaysRetry`, defined in `ToolExecutorTest.php`; a
single-file-run artefact present on php8.5 too, not this PR.)

Checked the lane against the daemon v3.68.0 source in this checkout
(`.claude/hooks-daemon/src/claude_code_hooks_daemon/daemon/cli.py` `cmd_format_markdown`): the
`format-markdown [--check] <path>` CLI, `Would reformat:` / `Reformatted:` lines and exit codes match
what the lane parses. Writable mode exits 0 after rewriting, so no false crash there.

#### Note N1 (reproduced): a default path the consumer gitignores crashes the lane

`src/Pipeline/Lane/MarkdownFormatTool.php:63-66` keeps every configured path that exists, then
`:86-90` treats any non-zero exit without `Would reformat:` as a crash. The daemon refuses an
explicitly named gitignored target with exit 1 and `ERROR: <path> is gitignored ...`
(reproduced: `hooks-daemon format-markdown --check untracked/scratch/verify51/ignoreddocs` → that
message, exit 1). So a consumer with the daemon installed and a gitignored `docs/`, `CLAUDE/` or
`CLAUDE.md` (e.g. generated API docs in `docs/`) goes from a green pipeline to a crashed
`markdownFormat` lane on upgrade, in writable runs as well as read-only, with no finding to fix; the
only way out is `withMarkdownFormatPaths()`. The test `aFailureThatNamesNoFileToReformatIsACrash`
pins this as intended, and `docs/tools/markdownFormat.md:38-39` only covers ignored files *under* a
listed directory. Suggest: drop a listed path git ignores (`git check-ignore`) along with the
non-existent ones, and document it next to line 37.

#### Note N2 (read): relative paths defeat the daemon's own root walk in a monorepo

`MarkdownFormatTool.php:108-109` passes the configured path relative, cwd = project root. The
daemon's `_enclosing_project_root` walks `Path(path).parents`; for a relative `docs` that is only
`.`, so when the daemon lives at the work-tree root above a package (the case
`HooksDaemonCliLocator` exists for), the walk never reaches the root's `.claude/hooks-daemon.yaml` and
`daemon.exclude_paths` is not applied. The lane can then rewrite files the daemon itself is configured
to leave alone. Passing `$root . '/' . $path` would let the daemon find its config. Low impact
(monorepo plus exclude_paths only); part of the cause is daemon-side.

#### Note N3 (read): a misspelt path is dropped silently

`MarkdownFormatTool.php:63-66` drops a non-existent path without a word, so a typo in
`withMarkdownFormatPaths('doc')` formats nothing and passes (or skips) silently. Documented at
`docs/tools/markdownFormat.md:37`, so a deliberate choice; a printed "not present, skipped: …" line
would make it visible. The plan's goal "never passes silently" is met only for the all-missing case.

#### Note N4 (read): mixed error and pending in one directory is reported as a finding

When `--check` over a directory hits one unreadable/conflicted file and one pending file, the daemon
exits 1 and prints `Would reformat:`, so `MarkdownFormatTool.php:86` classifies it as a failure, not a
crash. The run still fails and the daemon's `ERROR:` line is printed, so this is cosmetic.

#### Note N5 (read): stale records

- PR body still says "Stacked on #49 … Merge #49 first"; #49 is merged and the PR targets php8.5.
- `CLAUDE/Plan/00018-markdown-formatter-matching-hooks-daemon/PLAN.md:56` Task 3.4 still says
  "(stacked on Plan 00017's PR #49)".
- `CLAUDE/Plan/README.md:13` lists Plan 00018 as "Not Started" with the pre-decision description
  ("Engine is an Owner decision …", "every generator routes through it"), while PLAN.md is In Progress
  with the engine decided and no generator routed through it.

### Mergeability

MERGEABLE; only Plan 00018's changes against php8.5.

## #53 — CLAUDE/pr-verification.md

### Scope / rules

Two files: `CLAUDE/pr-verification.md` (new) and a pointer in CLAUDE.md's knowledge policy. Docs only;
commit 31f309a carries `Changelog: none — agent procedure document, nothing a consumer receives`
(CLAUDE/ and CLAUDE.md are what php-qa-ci itself reads, not a consumer). Branch `chore/` allowed.
No suppressions.

### Correctness (settings section checked against live state)

- Lines 108-110 (classic protection, zero approvals, checks kept): matches GraphQL for php8.5 and php8.4.
- Line 111-116 (`protect` ruleset, 0 approvals, unattributed option Copilot-only): matches
  `rules/branches/php8.5` and the research report.
- Line 117 (`php8.4` has no ruleset): matches (`rules/branches/php8.4` is empty).

#### Note N6 (reproduced): the second php8.5 ruleset is not mentioned

`CLAUDE/pr-verification.md:111` says php8.5 "also has the `protect` ruleset"; php8.5 has a second,
23328934 `protect-default-branch` (deletion, non_fast_forward). It requires no review so the
conclusion stands; one clause would make the section complete.

#### Note N7 (read): PR body contradicts the merged text

The PR body still says the "unattributed changes" setting is what the procedure depends on and that
"while it is on, an agent can't merge its own pull request at all". The 31f309a text (and the
research) says the opposite. The merge commit carries the body, so it should be updated before merge.

#### Note N8 (read): links into Plan 00019 resolve only after #59

`CLAUDE/pr-verification.md:106,116` link to `Plan/00019-agent-pr-merge-gate/`, which exists only on
#59. Expected by the stated merge order (#59 first). The markdownLinks lane scans README.md and docs/,
not CLAUDE/, so CI would not catch a broken link here if the order slipped.

### Mergeability

MERGEABLE; its own commits plus a merge of php8.5.

## #59 — Plan 00019 records

### Scope / rules

Six files under `CLAUDE/Plan/` (PLAN.md, research report, three journal day-files, README row). Records
only; no consumer-visible path. No secrets: the research names accounts (`lts-bob`, `LTSCommerce`) and
a work email already public in commit metadata. Branch `chore/` allowed.

### Correctness

The research report is sourced, quotes GitHub Docs, separates authoritative from anecdotal claims, and
its "live state" is explicitly dated 2026-10-05 (when classic protection required 1 approval). It is a
point-in-time record, which is right for a research file; the current state is in PLAN.md Task 2.1
and #53.

#### Note N9 (read): PLAN.md lags its own journal

- `CLAUDE/Plan/00019-agent-pr-merge-gate/PLAN.md:62` Task 2.2 is unticked, but the 17:35 journal entry
  on aaf7dad records it confirmed, and live GraphQL shows 0 approvals on both lines.
- `PLAN.md:64` Task 2.3 is delivered by #53 (31f309a) and is unticked; fine until #53 merges.
- PR title/body still say "Owner decision pending" and Tasks 2.1–2.3 "await the Owner's choice";
  Task 2.1 is decided in the diff.

### Mergeability

MERGEABLE.
