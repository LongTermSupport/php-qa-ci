# GitHub ruleset parameter `require_extra_approval_for_unattributed_changes`

Researched 2026-10-05 by a read-only research sub-agent; no settings, comments or other writes on
GitHub. The coordinator re-fetched the primary Docs quote under Q1 and confirmed it verbatim.

## Summary

- **What it means (authoritative, GitHub Docs):** the parameter is the API name of the ruleset option
  **"Require an additional approval for unattributed Copilot pull requests"**. "Unattributed" means a
  pull request that **GitHub Copilot opened under its own app identity rather than on behalf of a
  person**, for example one started from a shared Slack or Teams thread. The ruleset then requires
  **one more approval than the configured count**.
- It has **nothing to do with** commit signing, commit author or committer emails, `Co-Authored-By`
  trailers (Claude or any other), or who pushed. The docs name no such criterion.
- **Status:** public preview, "enabled by default, for both new and existing rulesets". It arrived with
  the Copilot-in-Slack and Copilot-in-Teams changelog posts of 2026-08-21.
- **With `required_approving_review_count: 0` it does nothing.** The docs say so explicitly.
- **For php-qa-ci it is not what blocks anything.** lts-bob's PRs are blocked by the **classic branch
  protection rule (1 required approving review)**, because a PR author cannot approve their own PR.
  Removing the Claude trailer or changing how commits are signed changes nothing. The commits are
  already signed and verified, and every author maps to a real GitHub account.

## Findings per question

### Q1. What "unattributed changes" means

Primary source: GitHub Docs, *Available rules for rulesets*, section "Additional approval for
unattributed Copilot pull requests"
(<https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets>).
Text fetched verbatim through `docs.github.com/api/article/body`:

> **Require an additional approval for unattributed Copilot pull requests** is enabled by default, for
> both new and existing rulesets. When Copilot opens a pull request that isn't attributed to a person,
> the ruleset requires one more approval than the number you configured. For example, a ruleset that
> requires one approval requires two approvals from people with write access.

> Requiring one approval usually means two people are involved in a change: the person who wrote it
> and the person who approved it. That assumption doesn't hold when Copilot opens a pull request under
> its own app identity instead of on behalf of a person, for example when you prompt it from a shared
> context such as a group thread or channel.

> If you clear this setting, these pull requests require only the number of approvals you configured.
> If you also require an approval from someone other than the last person to push, at least one
> approval must cover the last push and come from someone other than Copilot.

The changelog posts agree, and frame it the same way: attribution is about **the PR being attributed
to the Copilot app identity**.

- *The new GitHub Copilot experience in Slack* (2026-08-21,
  <https://github.blog/changelog/2026-08-21-the-new-github-copilot-experience-in-slack/>): "Issues
  and pull requests created from the conversation are attributed to the Copilot app identity" …
  "Repository administrators can require an additional approval for any pull request attributed to
  the Copilot app identity before it can merge." … "Requiring an additional approval keeps a human
  in the loop before agent-authored work ships."
- *Shared agentic work with GitHub Copilot in Microsoft Teams* (2026-08-21,
  <https://github.blog/changelog/2026-08-21-shared-agentic-work-with-github-copilot-in-microsoft-teams/>):
  "Repository administrators can now require an additional approval for any pull request attributed
  to the Microsoft Teams Copilot integration identity before it can merge." … "If you require two
  approvals in a repository, with this enabled you will need three for Copilot-created pull requests."

The **REST API reference does not document the field at all**. `docs.github.com/en/rest/repos/rules`
contains no occurrence of "unattributed", and this is tracked upstream as
github/rest-api-description#7246
(<https://github.com/github/rest-api-description/issues/7246>): "The `pull_request` rule carries
`require_extra_approval_for_unattributed_changes` and `ignore_approvals_from_contributors` …". The
mapping from the API key to the docs option rests on the name and the timing. GitHub does not state
it, but no other ruleset option matches.

**Conflicting third-party claims.** Several GitHub issues and PRs in other repositories, mostly
written by agents, describe the parameter differently. These are not authoritative and some
contradict the docs:

- "commits whose author is not attributed to a GitHub account" (for example
  francovp/cabros-bot#1227, <https://github.com/francovp/cabros-bot/issues/1227>, about
  `copilot@anthropic.com` commits)
- "making app-opened pull requests wait for a human approval, even with zero required approvals"
  (cbusillo/codex-skills#792, <https://github.com/cbusillo/codex-skills/pull/792>)

The zero-approvals claim directly contradicts the docs. The commit-author theory has no GitHub source
behind it. I could not verify either claim. Treat them as unconfirmed anecdotes, possibly describing
preview-era behaviour or a different cause. What the evidence does support is the docs' definition
above.

### Q2. When it was introduced, GA or preview

- It was introduced alongside the Copilot Slack and Teams integrations, whose changelog posts are
  dated **2026-08-21**. Both posts describe a **public preview**.
- The docs section carries "This feature is in public preview and subject to change."
- It is "enabled by default, for both new and existing rulesets". This explains why ruleset
  18472656 shows `true` although nobody set it. Third-party reports also say the API fills in
  `true` when a create or update payload omits the key.

### Q3. Does it act on its own when `required_approving_review_count` is 0?

No, according to the docs: "This setting has no effect if the ruleset requires zero approvals, so
repositories that use pull requests as a record of changes rather than to gate on approvals are
unaffected." It is a +1 on the configured count, applied only to unattributed Copilot PRs. It does
not add a standalone requirement.

### Q4. Would dropping the Claude trailer, or signing commits, change anything? What actually blocks lts-bob's PRs?

Nothing changes, on both counts. This is the live state, read-only, on 2026-10-05:

- **Ruleset 18472656 "protect"** (target `~DEFAULT_BRANCH` = `php8.5`, active, no bypass actors): the
  `pull_request` rule has `required_approving_review_count: 0` and
  `require_extra_approval_for_unattributed_changes: true`. Given Q3, this rule demands no approval.
  The ruleset also demands nothing else relevant to review.
- **Ruleset 23328934 "protect-default-branch"**: only `deletion` and `non_fast_forward`.
- **Classic protection**, read through GraphQL `refUpdateRule` because the REST protection endpoint
  returns 404 to a write-role token:
  - `php8.5`: `requiredApprovingReviewCount: 1`, `requiredStatusCheckContexts: ["QA Pipeline"]`,
    `requiresSignatures: false`.
  - `php8.4`: `requiredApprovingReviewCount: 1`, with checks `QA Pipeline` and
    `ShellCheck (severity=warning)`.
- **PR #49** (lts-bob, `feature/plan-00017-fix-deferred-defects` into `php8.5`):
  `reviewDecision: REVIEW_REQUIRED`, `mergeStateStatus: BLOCKED`, 0 reviews,
  `viewerCanMergeAsAdmin: false`.
- **PR #49's 54 commits:** all are **signed and verified** (`reason: valid`, SSH signatures). They
  split into 25 authored and committed by `LTSCommerce` (joseph@ltscommerce.dev) and 29 by `lts-bob`.
  The `noreply@anthropic.com` co-author resolves to a GitHub user (`claude`). Nothing in them is
  unattributed in any sense, and the PRs are opened by a person, not by the Copilot app.
  This corrects the brief's guess that the commits are unsigned.

**The blocker is the classic rule's 1 required approval.** GitHub Docs, *Approving a pull request with
required reviews*
(<https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/approving-a-pull-request-with-required-reviews>):
"Pull request authors cannot approve their own pull requests." Classic protection, from *About
protected branches*
(<https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches>):
"If you enable required reviews, collaborators can only push changes to a protected branch via a pull
request that is approved by the required number of reviewers with write permissions." lts-bob has
WRITE, not admin, so lts-bob can neither approve nor admin-merge. Any other write-or-above user,
LTSCommerce included, can approve. The ruleset does not set `ignore_approvals_from_contributors`,
and that option is not available for classic protection. The same docs page notes: "Repository owners
and administrators can merge a pull request even if it hasn't received an approving review."

Signing does matter in one place: `requiresSignatures` is off, so it is not a gate today. Had it been
on, unsigned commits would block (*About protected branches*, "Require signed commits"). The commits
are signed anyway.

## What this means for php-qa-ci

- `require_extra_approval_for_unattributed_changes: true` is GitHub's preview default and is
  **inert** here, because the ruleset's count is 0 and no Copilot-app PRs are opened. It would only
  start to matter if the ruleset count were raised above 0 **and** Copilot opened PRs under its app
  identity.
- Setting it to `false` explicitly would only be hygiene. It removes a confusing default and guards
  against future drift if GitHub changes the preview's behaviour, but it would not unblock anything.
- The trailer and signing are irrelevant to merge-blocking. The single human approval required by
  classic protection on `php8.5` and `php8.4` is, by design, the gate on lts-bob's PRs.

## Options

These are options for letting the single agent account lts-bob merge its own PRs while keeping
required status checks. All of them are Owner decisions, because each one narrows a defence.

| Option                                                                                                                                                                                   | Keeps checks                                              | Trade-off                                                                                                                                                                                                                                                                                                               |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A. Set classic `requiredApprovingReviewCount` to 0 (keep "require PR" and the required checks), or move all protection into one ruleset with a `required_status_checks` rule and count 0 | Yes                                                       | Simplest. It removes the human review gate for everyone, not just lts-bob. php8.4 needs the same change.                                                                                                                                                                                                                |
| B. Classic "Allow specified actors to bypass required pull requests" with lts-bob (allowed because LongTermSupport is an Organization)                                                   | Status checks are a separate setting                      | The docs describe it as letting actors "push code to the branch without creating pull requests when they're required", which also opens direct pushes. I did not verify from the docs whether it also lifts the review count on the merge button. Test it before relying on it.                                         |
| C. Ruleset bypass list, mode "For pull requests only"                                                                                                                                    | Only if checks live in a separate, non-bypassable ruleset | Individual users are **not** eligible: only roles, teams, apps and Dependabot. You would need a team containing lts-bob, or a GitHub App identity. Bypass skips the whole ruleset. It also does **not** bypass classic protection, so A or B is still needed for the classic 1-approval rule. It leaves an audit trail. |
| D. A second identity approves, either a GitHub App or a separate bot user with write access                                                                                              | Yes                                                       | Keeps the rule nominally in place, but an automated approval is a rubber stamp: review theatre that defeats the gate's purpose.                                                                                                                                                                                         |
| E. Status quo: a human (LTSCommerce or an admin) approves or admin-merges                                                                                                                | Yes                                                       | Matches the "a human pushes/releases" stance in the project instructions. The cost is human latency.                                                                                                                                                                                                                    |
| F. Set `require_extra_approval_for_unattributed_changes: false` explicitly                                                                                                               | n/a                                                       | No unblocking effect. Hygiene only.                                                                                                                                                                                                                                                                                     |

The ruleset bypass details come from *Creating rulesets for a repository*
(<https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository>):

> The following are eligible for bypass access: Repository admins, organization owners, and
> enterprise owners; The maintain or write role, or custom repository roles based on the write role;
> Teams …; GitHub Apps; Dependabot.

> For pull requests only … The selected actor is now required to open a pull request … The actor can
> then choose to bypass any branch protections and merge that pull request.

The classic bypass wording comes from *Managing a branch protection rule*
(<https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/managing-a-branch-protection-rule>):

> Actors may only be added to bypass lists when the repository belongs to an organization.

## Sources

- GitHub Docs, Available rules for rulesets:
  <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets>
- GitHub Docs, Creating rulesets for a repository:
  <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository>
- GitHub Docs, About protected branches:
  <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches>
- GitHub Docs, Managing a branch protection rule:
  <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/managing-a-branch-protection-rule>
- GitHub Docs, Approving a pull request with required reviews:
  <https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/approving-a-pull-request-with-required-reviews>
- GitHub Docs, REST rules endpoints, where the field is absent:
  <https://docs.github.com/en/rest/repos/rules>
- GitHub Changelog, 2026-08-21, Copilot in Slack:
  <https://github.blog/changelog/2026-08-21-the-new-github-copilot-experience-in-slack/>
- GitHub Changelog, 2026-08-21, Copilot in Microsoft Teams:
  <https://github.blog/changelog/2026-08-21-shared-agentic-work-with-github-copilot-in-microsoft-teams/>
- github/rest-api-description#7246, the undocumented field:
  <https://github.com/github/rest-api-description/issues/7246>
- Third-party, non-authoritative and conflicting with the docs:
  <https://github.com/francovp/cabros-bot/issues/1227>,
  <https://github.com/cbusillo/codex-skills/pull/792>
- Live repo state, read-only:
  - `gh api repos/LongTermSupport/php-qa-ci/rulesets/{18472656,23328934}`
  - `rules/branches/php8.5`
  - GraphQL `refUpdateRule` for `php8.5` and `php8.4`
  - PR #49 commits and their verification
