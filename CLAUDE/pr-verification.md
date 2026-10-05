# Verifying a pull request before an agent merges it

When work is fully agent-driven, the agent that wrote a pull request is also the one that merges
it, and no human reads the diff in between. The pre-push battery
([prepush-verification.md](prepush-verification.md)) and CI prove the tools pass; they do not
prove the change is the right change. So before an agent merges a pull request no human has
reviewed, a **separate sub-agent verifies it**, and the merge waits on its verdict. This is the
Owner's standing rule for agent-driven merges into `php8.5`, `php8.4` or any other line, recorded
here.

A human approving the pull request on GitHub replaces this step for that pull request.

## Who verifies

- A **fresh** sub-agent (`general-purpose`), never a fork of the author's session: a fork inherits
  the author's reasoning, and the point is a reader who has not already decided the change is
  right.
- **Read-only towards the pull request.** It does not edit, commit, push, comment, approve or
  merge, and it never checks out or writes in the author's checkouts (the main working tree or any
  worktree under `untracked/worktrees/`). It reads through `gh` and `git show`/`git diff` against
  the remote refs.
- **It may reproduce.** To run a single lane, a single test or a small fixture against the head,
  it makes its own throwaway checkout (`git worktree add --detach untracked/worktrees/verify-<pr> origin/<branch>`) or a fixture under `untracked/scratch/`, and removes the worktree when done.
  A finding it reproduced is worth more than one it read, and the report says which.
- It does not run the full pipeline (with the hooks daemon installed, sub-agents are denied it;
  see [qa-orchestration.md](qa-orchestration.md)), and CI has run it.

## What it checks

For each pull request, with evidence for each:

1. **CI**: on the head commit, every check concluded success, or was skipped by its own
   condition (say which, and why that is expected). A check still running is not a pass: the
   verdict says so, and the merge waits for it.
2. **Scope**: the diff matches the description; nothing unrelated, no debug output, no secrets,
   no hand-edited lock file.
3. **Project rules**: no new suppression, baseline or `ignoreErrors` entry without the Owner's
   decision; Defence Before Fix ordering, the detector or red test before the fix
   ([DefenceBeforeFix.md](DefenceBeforeFix.md)); a `## Unreleased` entry or a
   `Changelog: none — <reason>` trailer for a change to a watched path
   ([releases.md](releases.md)); the branch name ([branch-policy.md](branch-policy.md)).
4. **Correctness**: the substantive code and its tests, read for real defects and for tests that
   cannot fail. Two questions in particular, because CI cannot answer them:
   - **Does every documented route work?** A template, an example or a documented override is a
     route a consumer takes; follow it through the changed code, not only the route the tests
     use. (A template handing a lane `__DIR__ . '/../src'` where the tests passed a canonical
     path.)
   - **What inputs does nothing here exercise?** This repository's own configuration and CI cover
     one shape of input; a consumer's may omit a key, use a relative path or sit in a monorepo.
     Ask what the change does with the shapes this repository never has, and whether something
     that worked before the change breaks after it. (A derived config that dropped a default the
     tool applied to an absent key.)
5. **Mergeability**: no conflict with the base; a stacked pull request carries only its own
   commits.

It writes the full report to a file, the plan's `subagent-reports/` folder when the pull request
belongs to a plan and `untracked/agent-reports/` otherwise, and replies with one verdict per pull
request: **PASS**, **PASS WITH NOTES** or **FAIL**, with `file:line` and the reason for every
blocking finding.

## What the merging agent does with the verdict

- **FAIL**: fix the findings on the branch, Defence Before Fix as for any defect, push, and verify
  again. Never merge over a FAIL, and never argue a finding away instead of fixing it; a finding
  the agent believes wrong goes to the Owner.
- **PASS WITH NOTES**: before merging, fix any note that is cheap and in scope on the branch (which
  then needs a new verdict), and raise every other note as an issue or a plan task so it is not
  lost.
- **PASS**: merge with `gh pr merge --merge`.

The verdict covers the head commit and the base it read. A push, a retarget or a change of base
after the verdict needs a new verdict before the merge: a retarget changes which workflows run and
what the diff is measured against. The merging agent comments the verdict, the verified head
commit and the findings on the pull request, so the merge carries its evidence; a report under
`untracked/` is not visible to anyone else.

## The repository settings this relies on

An agent can merge only where no rule demands an approval it cannot give: GitHub never lets an
author approve their own pull request, and the agent account has neither admin rights nor a place
on a bypass list.

- **`php8.5`**: the `protect` ruleset requires no approving review, but its option **"Require
  extra approval for unattributed changes"** (`require_extra_approval_for_unattributed_changes`)
  is on. GitHub documents it for unattributed Copilot pull requests; what this repository
  observes is that every agent pull request, whose commits carry the `Co-Authored-By: Claude`
  trailer, waits on an approval from someone other than its author while it is on. GitHub
  enabled the option on existing rulesets when it introduced it, and turns it on whenever a
  ruleset is saved without it, so a later ruleset edit can switch it back on.
- **`php8.4`**: no repository ruleset applies, yet GitHub refuses an agent's merge there with
  "the base branch policy prohibits the merge". The rule comes from branch protection or an
  organisation ruleset, neither of which the agent account can read.

Changing either needs repository or organisation admin rights: they are the Owner's settings.
While a merge is blocked by one, this verification step still runs, and the pull request waits on
the Owner rather than on the verdict.
