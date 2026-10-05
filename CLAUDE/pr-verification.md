# Verifying a pull request before an agent merges it

When work is fully agent-driven, the agent that wrote a pull request is also the one that merges
it, and no human reads the diff in between. The pre-push battery
([prepush-verification.md](prepush-verification.md)) and CI prove the tools pass; they do not
prove the change is the right change. So before an agent merges a pull request no human has
reviewed, a **separate sub-agent verifies it**, and the merge waits on its verdict. This is the
Owner's standing rule for agent-driven merges into `php8.5`, `php8.4` or any other line.

A human approving the pull request on GitHub replaces this step for that pull request.

## Who verifies

- A **fresh** sub-agent (`general-purpose`), never a fork of the author's session: a fork inherits
  the author's reasoning, and the point is a reader who has not already decided the change is
  right.
- **Read-only.** It does not edit, commit, push, comment, approve or merge, and it does not check
  out branches in the author's working tree; it reads `gh` and `git show`/`git diff` against the
  remote refs.
- It does not run the full pipeline (sub-agents are denied it, and CI has run it); it may run a
  single lane or a single test with `-t` when a finding needs one.

## What it checks

For each pull request, with evidence for each:

1. **CI**: every check on the head commit concluded success.
2. **Scope**: the diff matches the description; nothing unrelated, no debug output, no secrets,
   no hand-edited lock file.
3. **Project rules**: no new suppression, baseline or `ignoreErrors` entry without the Owner's
   decision; Defence Before Fix ordering, the detector or red test before the fix
   ([DefenceBeforeFix.md](DefenceBeforeFix.md)); a `## Unreleased` entry or a
   `Changelog: none — <reason>` trailer for a change to a watched path
   ([releases.md](releases.md)); the branch name ([branch-policy.md](branch-policy.md)).
4. **Correctness**: the substantive code and its tests, read for real defects, tests that cannot
   fail, and behaviour the docs contradict.
5. **Mergeability**: no conflict with the base; a stacked pull request carries only its own
   commits.

It writes the full report to a file, the plan's `subagent-reports/` folder when the pull request
belongs to a plan and `untracked/agent-reports/` otherwise, and replies with one verdict per pull
request: **PASS**, **PASS WITH NOTES** or **FAIL**, with `file:line` and the reason for every
blocking finding.

## What the merging agent does with the verdict

- **FAIL**: fix the findings on the branch, push, and verify again. Never merge over a FAIL, and
  never argue a finding away instead of fixing it; a finding the agent believes wrong goes to the
  Owner.
- **PASS WITH NOTES**: merge, and raise each note as an issue or a plan task so it is not lost.
- **PASS**: merge with `gh pr merge --merge`.

The verdict covers the head commit it read. A push after the verdict, for any reason, needs a new
verdict before the merge. The merging agent comments the verdict, the verified head commit and
the report's path on the pull request, so the merge carries its evidence.

## The repository setting this relies on

GitHub's ruleset option **"Require extra approval for unattributed changes"**
(`require_extra_approval_for_unattributed_changes`) blocks a pull request whose commits carry an
author GitHub does not link to an account, which includes the `Co-Authored-By: Claude` trailer
every agent commit carries, until someone other than the author approves it. While it is on, an
agent cannot merge its own pull request at all, and this verification step has nothing to gate.
GitHub turns it on whenever a ruleset is saved without it, so a ruleset edit can switch it back
on. Changing it needs repository (or, for an organisation ruleset, organisation) admin rights,
which the agent account does not hold: it is the Owner's setting.
