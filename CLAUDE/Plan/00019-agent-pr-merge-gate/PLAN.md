# Plan 00019: agent pr merge gate

**Status**: In Progress
**Created**: 2026-10-05
**Owner**: dev
**Priority**: High

## Overview

Agent-driven pull requests are opened and pushed by the `lts-bob` account, which has push rights
but not admin. Every one of them stops at `REVIEW_REQUIRED`: GitHub never lets an account approve
its own pull request, and the base branches demand an approving review. Pull requests #49, #50 and
#53 had all passed independent sub-agent verification
([CLAUDE/pr-verification.md](../../pr-verification.md), on #53) and still could not merge.

Two settings were candidates. Classic branch protection on `php8.5` and `php8.4` requires one
approving review (GraphQL `refUpdateRule.requiredApprovingReviewCount = 1`). Ruleset `protect`
(id 18472656, the default branch) requires none, but has
`require_extra_approval_for_unattributed_changes` on. The Owner asked whether the
"unattributed" option is commit signing or the Claude `Co-Authored-By` trailer, and whether
dropping the trailer would unblock agent pull requests. This plan finds the sourced answer and
records the setting change that lets verified agent pull requests merge.

## Goals

- A sourced answer to what GitHub's "unattributed changes" ruleset option checks
- The settings change that lets a verified `lts-bob` pull request merge, chosen by the Owner and
  recorded in [CLAUDE/pr-verification.md](../../pr-verification.md)
- An Owner decision on the Claude attribution trailer on future commits and pull requests

## Non-Goals

- Rewriting the history of open or merged pull requests to remove existing trailers
- Changing settings with an agent token; repository administration is the Owner's step

## Tasks

### Phase 1: Research

- [x] ✅ **Task 1.1**: Research the ruleset option and the approval rules; report in
  [research-github-unattributed-changes.md](research-github-unattributed-changes.md)

**Finding:** "unattributed changes" is GitHub's public-preview option *Require an additional
approval for unattributed Copilot pull requests*. It is on by default and adds one approval to a
pull request that Copilot opens under its own app identity. It has no effect when the ruleset
requires zero approvals, as `protect` does. It ignores commit signing, author emails and
`Co-Authored-By` trailers, and #49's commits are all signed and verified. What blocks `lts-bob` is
classic protection's one required approval on `php8.5` and `php8.4`, because an author cannot
approve their own pull request. Dropping the Claude trailer unblocks nothing.

### Phase 2: Decision and settings

- [ ] ⬜ **Task 2.1**: Owner chooses the merge-gate setting from the report's Options (A: zero
  required approvals with the checks kept; B: classic bypass for `lts-bob`, which also allows
  direct pushes; D/E: a second approver) and, separately, the attribution policy
- [ ] ⬜ **Task 2.2**: Owner applies the settings; confirm with the GraphQL `refUpdateRule` query
  and a `reviewDecision` that is no longer `REVIEW_REQUIRED`
- [ ] ⬜ **Task 2.3**: Record the chosen settings in
  [CLAUDE/pr-verification.md](../../pr-verification.md) and, if attribution is dropped, in
  `CLAUDE.md` and `.claude/settings.json`

## Success Criteria

- [x] The research report answers what "unattributed changes" means, with quoted sources
- [ ] A verified `lts-bob` pull request merges with `gh pr merge --merge` and no human approval

## Delivery & Milestones

- <!-- milestone or delivery commit hash -->
