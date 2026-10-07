# PR #51 verification, round 4

- **PR**: #51 `feature/plan-00018-markdown-format-lane` -> `php8.5`
- **Head verified**: `1b2d248218f78a48aa3dc8524858a5998fc65d40`
- **Base read**: `origin/php8.5` at `34f0661` (merge base = base tip; branch is up to date)
- **Procedure**: `CLAUDE/pr-verification.md` as on `origin/php8.5`
- **Verdict**: **PASS**

## 1. CI on 1b2d248 (all runs' headSha confirmed = 1b2d248)

| Check              | Result                       | Run                                                                                                       |
| ------------------ | ---------------------------- | --------------------------------------------------------------------------------------------------------- |
| QA Pipeline        | pass (2m35s)                 | 37673756177 (`CI`, pull_request)                                                                          |
| Detect PHP Version | pass                         | 37673748053 (`PHP QA Pipeline`, push)                                                                     |
| PHP QA (8.5)       | pass (25m11s)                | 37673748053                                                                                               |
| Coverage Report    | skipped by its own condition | `qa.yml:316` `if: github.event_name == 'pull_request'`; this run is a push event, so the skip is expected |

Required status context on `php8.5` is `QA Pipeline` only; `requiredApprovingReviewCount` 0.
Polled until no check was pending (about 25 minutes).

## 2. Scope

`git diff origin/php8.5...1b2d248` touches 32 files, all Plan 00018: the lane
(`src/Pipeline/Lane/MarkdownFormatTool.php`), the daemon locator, config builder/DTO wiring,
registry/shipped-tools entries, their tests, docs (`docs/tools/markdownFormat.md`, pipeline,
upgrading, rule index, CLAUDE.md lane listing and active-defences line), the plan's own
records, the CHANGELOG entry, and whitespace-only reformats produced by the lane on other plan
records (00017 journal, 00010 DECISIONS trailing blank line and REAUDIT, 00019 journal in
1b2d248, and a table rule width in `docs/upgrading-to-8.5.md`). `git diff -w` on the non-00018
plan files is empty except the trailing blank line and Plan README index row (00018's own row).
No debug output, no secrets, no lock-file edits, no new suppression / baseline / ignoreErrors.

The two merges (5190f5d, be32dfb) bring in #59 and #53; the three-dot diff shows no residue
from them.

## 3. Project rules

- **DBF order, reproduced** in a detached worktree (`composer install`, `bin/phpunit -c qaConfig/phpunit.xml --no-coverage`):
  - at `01b5c14`: `MarkdownFormatToolTest::aPathGitIgnoresIsDroppedAndNamed` FAILS at
    `tests/Small/Pipeline/Lane/MarkdownFormatToolTest.php:218` (expected `['LC_ALL' => 'C']`,
    actual `[]`). Red reproduced.
  - at `94f78c7`: the same file is green (14 tests).
  - at `1b2d248`: Small + Large (`MarkdownFormatToolGitTest`) green (16 tests).
  - The Large test's new guard (temp dir outside any work tree, N14) passes at red too; it is a
    precondition assertion, not a red test, which is correct for N14.
- **Changelog**: the lane's `### Added` entry under `## Unreleased` is present and still
  accurate. 01b5c14/94f78c7 change the lane covered by that entry (no trailer needed);
  merges and 1b2d248 carry `Changelog: none — ...` trailers. CI's changelog lane passed.
- **Branch name**: `feature/` prefix, allowed.

## 4. Correctness: LC_ALL=C on the ignore probe

- `SymfonyProcessRunner::run` (unchanged, already on base) builds the env as
  `[...getenv(), ...$spec->env]` when `env` is non-empty, so PATH, HOME, GIT\_\* and the rest are
  inherited; only `LC_ALL` is overridden. The 94f78c7 message's "the runner merges..." describes
  existing behaviour, not a change in this commit (no runner diff).
- `LC_ALL=C` affects only git's messages/collation; with `core.quotePath=false` paths are
  emitted as raw bytes. Reproduced: under `LC_ALL=C`, `git check-ignore` on a non-ASCII ignored
  dir (`igné`) prints `igné` unquoted; outside a repo with `LANGUAGE=de LANG=de_DE.UTF-8 LC_ALL=C`
  git prints `fatal: not a git repository ...` (gettext ignores `LANGUAGE` under the C locale), so
  the `NOT_A_REPOSITORY` match holds. The C locale exists on every libc, so no environment loses
  git by this change.
- Test strength: the Small assertion fails if the env is dropped (shown by the red run).

## 5. Mergeability

`mergeStateStatus` CLEAN, `mergeable` MERGEABLE, `reviewDecision` empty (no approval required).

## Notes (non-blocking)

None new. Prior notes N13 and N14 are addressed. The mkplan three-space journal heading is
tracked as issue #65 and is outside this PR.
