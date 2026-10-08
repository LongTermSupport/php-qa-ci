# PR #67 verification — Plan 00017: complete, moved to Completed/

- Base: `php8.5` at `ee65a03636a94eba656188e77562e1146fa8e7ee` (equals the merge base; head is up to date)
- Head: `chore/plan-00017-complete` at `f5bf5c8e715a1266645f82f267c824978534327a`
- Commits: `73fdbf9` (the change) and `f5bf5c8` (merge of php8.5)
- Verdict: **PASS WITH NOTES**
- Method: read-only (`gh`, `git show`/`git diff` against origin refs). Nothing reproduced locally; every finding was read, not reproduced.

## 1. CI on the head

All checks on `f5bf5c8` completed: `QA Pipeline` (CI) SUCCESS, `Detect PHP Version` SUCCESS, `PHP QA (8.5)` SUCCESS.
`Coverage Report` SKIPPED by its own job condition (a post-job of the PHP QA Pipeline workflow; expected, not a required
check). The base's update rule requires only `QA Pipeline`, which is green.

## 2. Scope

Six files: two pure renames (journal 26-10-03 and the subagent report, 100% similarity); PLAN.md renamed with
status/task/criteria/delivery edits; a new journal day-file 26-10-08; the CLAUDE/Plan/README.md index row moved from
Active to Completed; the CLAUDE/releases.md approval wording. Matches the description. No code, no lock file, no debug
output, no secrets. No reference to the old plan path remains on the head (`git grep 00017-fix-every` outside the plan
directory hits only the new README row).

## 3. Project rules

- Branch prefix `chore/` is allowed for doc-only changes (CLAUDE/branch-policy.md:38).
- Changelog: no watched path is touched (qaConfig/qa.php:50-55 excludes CLAUDE/); commit `73fdbf9` carries
  `Changelog: none — plan records and maintainer docs, nothing a consumer receives` regardless.
- No suppression, baseline or ignoreErrors change. Defence Before Fix not applicable (no defect fixed).
- Both commits carry the Co-Authored-By trailer.

## 4. Correctness of the doc edits

Checked against GitHub:

- PR #50: MERGED into php8.4 at 2026-10-07T17:33:40Z, merge commit `9960f9756213fda0558498207cb96c5ddd4adf0a`; matches PLAN.md:77 and :89.
- CI push run on `9960f97`: `QA Pipeline` success, `ShellCheck (severity=warning)` success; supports the criterion at PLAN.md:83.
- Release `84.0.1` exists, target `9960f97`; `refs/tags/84.0.1` points at `9960f97`.
- PR #49: MERGED into php8.5 (`385b01a`); matches PLAN.md:3 and :88.
- Issue #36 has a comment by lts-bob (2026-10-08T11:17) pointing at #50, `9960f97` and the release.
- CLAUDE/releases.md:126-133: the php8.5 refUpdateRule reads `requiredApprovingReviewCount: 0`,
  `requiredStatusCheckContexts: ["QA Pipeline"]`, `requiresConversationResolution: true`, which matches the edited text.
  The REST protection endpoint returns 404 to the agent account, consistent with "readable by an admin only".
  Rulesets present: `protect`, `protect-default-branch` (consistent with pr-verification.md). Strictness and
  "not enforced on admins" are not readable by this account; that text is unchanged and was not verified.
- CLAUDE/releases.md:82-86: the release PR is merged after an Owner approval or the pr-verification.md verification,
  consistent with CLAUDE/pr-verification.md:11 and the zero-approval setting.
- CLAUDE/releases.md:120: "a merged pull request is the only write to php8.5" is accurate under these settings.

### Note N1 (non-blocking): the journal misattributes the #50 merge

`CLAUDE/Plan/Completed/00017-fix-every-deferred-defect-and-retire-the-record/JOURNAL/00017-Journal-26-10-08.md:37`
says "The Owner merged #50 into `php8.4`". GitHub's `mergedBy` for #50 is `lts-bob`, the account that authored #50
and #67 and posted the pre-merge verification comment on #50, i.e. the agent account, not the Owner. #50 has no
review; its merge rested on that verification comment (PASS WITH NOTES on `ecd4af3`). The journal is append-only, so
the fix is a `correction` entry with `--ref 11:18`, which the coordinator says will follow in a later PR. The PR
description does not repeat the error ("on the Owner's go-ahead" refers to the backport decision).

### Note N2 (informational)

Release `84.0.1` was published 2026-10-08T11:16:56Z by lts-bob "on the Owner's go-ahead". The go-ahead is not on any
GitHub record visible to this account, so it was not verified.

## 5. Mergeability

`mergeable: MERGEABLE`, `mergeStateStatus: CLEAN`, `reviewDecision: null`, required approvals 0. The merge base equals
the current `origin/php8.5`, so the head is up to date. Not stacked: it carries only its own commit plus a merge of the base.
