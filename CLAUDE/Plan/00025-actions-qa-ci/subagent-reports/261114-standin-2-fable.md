# Stand-in ruling 2: Plan 00025 (actions-qa-ci) pending questions after PR #441 and #443

This is the ruling of the automated awaiting-human stand-in, not the Owner's. A later real Owner
message overturns any line of it. The stand-in worked read-only: this report is its only write.

## What was read

- The earlier ruling `untracked/agent-reports/standin-0c1be677.md` and its journal entry
  `CLAUDE/Plan/00025-actions-qa-ci/JOURNAL/2026-11-14/025610Z-decision-32456d.md`
- `.github/dependabot.yml` (one `github-actions` entry, weekly, no `cooldown`)
- `qaConfig/branchNamePolicy.yaml` (only `extra_exempt_branches`: `php8.3`, `php8.4`, `php8.5`)
- `CLAUDE/branch-policy.md`
- Issue 310 comment 6380032729 (Decisions A and B, `self-repository`)
- `origin/php8.5` log: #441 merged as 7e5a8cd6, #443 as d0146ab4

Not done: no GitHub or zizmor documentation was fetched in this session. The two statements about
Dependabot's and GitHub's documented options below are from recall and are marked as such.

## Result: nothing CHOSEN, five DECLINED

Every option on the five questions is a gate change, an exception record, a release, an Owner
decision already named as theirs, or an option that does not exist. None is a pure engineering
option, so the coordinator has nothing new to build from this ruling.

## DECLINED

1. **Dependabot cooldown, option (a), a value of N days.** A cooldown holds an available update
   back, which departs from the same-day tool currency that `CLAUDE.md` records as an Owner
   decision. The earlier stand-in declined the 3-day value for that reason and said the value stays
   the Owner's; nothing has changed, and a different N is the same decision. The state therefore
   stays as merged (no cooldown). That is not a ruling for option (b) as an accepted gap: when the
   zizmor lane runs at Task 4.4 it reports `dependabot-cooldown`, the finding stays red, and
   recording it as an exception is also the Owner's. The question to put to the Owner is one line:
   "which wins for `.github/dependabot.yml`, same-day currency or the lane's cooldown, and if the
   cooldown, how many days".

2. **`dependabot/` branches and the branch-name policy.**

   - Option (a), add `dependabot/` to `extra_allowed_prefixes`: widens a gate. `CLAUDE/branch-policy.md`
     itself calls a new prefix "a recorded decision". Reserved.
   - Option (c), another prefix: from recall, Dependabot's only documented branch-name option is
     `pull-request-branch-name.separator`, which changes the separator (for example
     `dependabot-github_actions-…`) and never the leading `dependabot` word. No separator produces
     `chore/…`, so (c) is not available. Not verified against the documentation in this session;
     the coordinator may confirm it by reading GitHub's Dependabot options reference, which needs
     no ruling.
   - What remains is (b): Dependabot pull requests are red on `phpqaci.branchNamePolicy` and wait
     for a human. That is consistent with the earlier ruling, which did not extend the standing
     merge authorisation to them, so no agent would merge one anyway. The coordinator must not
     rename, re-open or re-push a Dependabot branch under an allowed prefix to get it green without
     the Owner: that is a way round the gate for a class of pull request the Owner has not ruled on.

3. **Decision B, `persist-credentials: false` at four checkouts.** Unchanged from the earlier
   ruling. Option 2 records `artipacked` as an exception (reserved). Option 3 accepts a found gap
   (the Owner's). Option 1 changes `release.yml` and `update-deps.yml`, which sit on the release
   and update path and whose result cannot be shown without a real release or update run; the
   `qa-autofix.yml` sites are a consumer template that nothing here runs. The poster of the comment
   said they would not change these without the Owner's choice, and the stand-in is not that.

4. **`self-repository`.** One option records two exceptions (reserved). The other is a `uses:`
   form the agent could not find in GitHub's documentation; the stand-in does not recall one
   either, and putting an undocumented form into `release.yml` and `update-deps.yml` is an
   unverifiable change on the release path. Establishing from GitHub's workflow-syntax
   documentation, and zizmor's own page for the audit, exactly which form is asked for and whether
   GitHub documents it is research the coordinator can do with no ruling; its result goes to the
   Owner on issue 310, not into a workflow.

5. **Tasks 4.2 and 4.4 release-shaped items, and Tasks 1.4 and 1.6.** `install.sh` filled in, the
   composite action and `versionPin` need the first actions-qa-ci release, which is reserved, and
   that release is itself behind the `*.secret*` guard refusal of two zizmor pages, which only a
   human narrows and which nobody routes around. Task 1.4 is a conformance statement made in the
   Owner's name. Task 1.6 sets the default strictness every consumer receives and one of its
   options narrows what is reported. All as declined before.

## What the coordinator may do without a ruling

Only research and records: confirm the Dependabot branch-name options and the `self-repository`
form from the vendors' documentation, and post the findings with the one-line cooldown question to
issue 310 for the Owner. No build, no detector and no red test follow from this ruling, because
nothing was chosen. The plan stays In Progress and awaiting a human.

## Ruling 2 amendment

Question 2 only; the other four rulings stand. The ruling above declined option (c) on a recalled
fact that was wrong. Checked in this session: the Dependabot schema
(`https://www.schemastore.org/dependabot-2.0.json`, definition `pull-request-branch-name`) has
`prefix`, "a custom string to use at the start of the branch name instead of the default", default
`dependabot`, 1 to 50 characters. `BranchNamePolicyDecision::DEFAULT_PREFIXES` is
`['feature/', 'bugfix/', 'chore/', 'hotfix/']`, matched with `str_starts_with`. Not checked by the
stand-in: GitHub's own options reference (the coordinator reports it documents `prefix`), and what
branch name the hosted service produces in practice.

**CHOSEN: option (c), `pull-request-branch-name: {prefix: chore}` in `.github/dependabot.yml`.**
Why it is a pure engineering option: it changes no gate and records no exception (the policy file
and the lane are untouched, and `chore/` is the prefix `CLAUDE/branch-policy.md` already gives to
dependency bumps); it adds no dependency; it is one file plus its test, undone by reverting one
pull request; and it makes the branches conform to the policy, which is the construction the
policy asks for, where option (a) would have changed the policy to fit the branches. Options (a)
and the "do not rename to get round the gate" line above are unaffected: (a) stays declined, and
configuring the source to produce a conforming name is not a way round the gate.

What to build, detector first:

1. Red test: a test that reads `.github/dependabot.yml` and asserts that every `updates` entry
   sets `pull-request-branch-name.prefix`, and that the prefix followed by the entry's separator
   (default `/`) is one of `BranchNamePolicyDecision::DEFAULT_PREFIXES`. Comparing prefix plus
   separator, not the bare word, also catches a later `separator: "-"` that would produce
   `chore-…`. Assert against the constant, not a copied list. It fails on the file as merged.
2. Then the one-line configuration change, with `separator` left at its default.
3. Normal route: `chore/` branch, changelog entry or `Changelog: none` trailer as the release
   rules decide, pre-push battery, fresh verifier.

Conditions: the standing merge authorisation is still not extended to Dependabot pull requests, so
a green one still waits for a human; no cooldown is added in the same change (question 1 stays
declined); and the first real Dependabot pull request is looked at to confirm its branch starts
`chore/`, since the test proves the configuration and not the service's behaviour. If it does not,
that is reported to the Owner, not answered by option (a).
