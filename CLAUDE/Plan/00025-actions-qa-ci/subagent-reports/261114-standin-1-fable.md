# Stand-in ruling: Plan 00025 (actions-qa-ci) pending questions

This is the ruling of the automated awaiting-human stand-in, not the Owner's. A later real Owner
message overturns any line of it. The stand-in worked read-only: nothing was edited, committed,
pushed, posted or released, and no guard was touched. The only writes are this report and two
scratch copies of the issue JSON (`untracked/scratch/standin-310.json`, `standin-344.json`).

## What was read

- `CLAUDE/Plan/00025-actions-qa-ci/PLAN.md`, `phase-4-status.md`, `design.md` §1, §3, §12, §14
- `CLAUDE/issue-sdlc.md` (the Owner-decision rule, lines 88 to 90)
- Issue 310 body and all nine comments (last comment 2026-10-24; no Owner reply since the four
  answers recorded on 2026-10-23), issue 344 body and its one comment
- The latest journal entry, `JOURNAL/2026-11-13/165622Z-decision-baee9f.md` (the earlier stand-in
  declined everything, with no sub-agent)
- Checked on disk: `templates/github-actions/php-qa-ci.yml` lines 82 to 88 (push list `main`,
  `master`, `php8.5`), `templates/github-actions/qa-autofix.yml` line 31 (header says require the
  "gate" check) and line 302 (job name `QA gate (read-only qa)`), `AUTO_COMMIT_FIXES` absent from
  `templates/` and `.github/` (only the `CHANGELOG.md` Removed entry names it),
  `timeout-minutes: 45` at line 132 of both `qa.yml` and the template
- Checked on GitHub (read-only): `LongTermSupport/actions-qa-ci` has default branch `main`, no
  release, and four open draft pull requests, 37 to 40

## The line the stand-in drew

`CLAUDE/issue-sdlc.md` also names "a fix with more than one defensible shape" as the Owner's.
Read strictly, that declines every item, which is what the earlier stand-in did. This stand-in was
told to choose where an option is a pure engineering choice, so it chose only where all of these
hold: the option is one already posted; it reports no less than today; it records no exception and
accepts no gap; it adds no dependency or tool; it does not touch the release path; and it is undone
by reverting one pull request. Where a choice was made on that basis and the strict reading would
have declined it, that is the judgement the Owner is most likely to want to review.

## CHOSEN

1. **Task 1.8, platforms: Linux amd64 and arm64 only** (design §14 item 6, the posted default).
   Build scope, following the Owner's "Linux" in Task 1.2. Every merged slice (bundle, `install.sh`,
   the zizmor pin for both architectures) is already built this way, and a platform added later
   breaks nothing.
2. **Task 1.8, repository shape: default branch `main`, one release line** (item 7). Already the
   fact: the repository's default branch is `main` and every slice merged there. Nothing to change.
3. **Task 1.8, no exec-rule kind** (item 8). It is a feature not built, not a check removed; it
   keeps the runtime-free install of Task 1.2 and can be offered later without breaking a record.
4. **Task 2.2 Decision A, how SHA pins are kept current: option 1, Dependabot's `github-actions`
   ecosystem with a cooldown.** Pinning to a full SHA is the construction the lane asks for.
   Option 2 (pinact) depends on the pinact provenance route, which is declined below; option 3
   records `unpinned-uses` as an exception, which is a suppression. Option 1 needs no new package
   or bundled tool and is the working agent's own recommendation. Conditions: (i) the standing
   merge authorisation is NOT extended to Dependabot pull requests, so each waits for a human
   (extending it is declined below); (ii) `WorkflowActionRuntimeTest` is extended first, red, to
   read `@<sha> # vN`; (iii) the cooldown is the smallest value the lane accepts without an
   exception, and the value stays the Owner's to change; (iv) the normal pre-push battery and a
   fresh verifier apply, since `qa.yml` and `release.yml` deploy. Judgement call flagged: the
   stand-in took Dependabot, a service of the platform already in use, not to be a "new
   dependency" in the sense of `issue-sdlc.md`. If the Owner reads it otherwise, this falls.
5. **Task 3.5 item 1, push trigger list: option (a), keep the list and the header instruction.**
   No change, and a test already holds it. Option (b) needs a declared difference in the
   template-drift check, which narrows that check. Option (c) starts a run on every push to every
   branch with a skipped job, which partly undoes the one-run-per-push shape the Owner chose in
   Task 1.1, and a skipped job carrying the required check's name is a risk the option does not
   address.
6. **Task 3.5 item 2, `AUTO_COMMIT_FIXES`: option (a), leave it removed and keep
   `qa-autofix.yml`.** This is the state on `php8.5` now, so nothing is built. Option (b) removes
   a shipped template from consumers; option (c) restores a commit that carries no `QA Pipeline`
   check, which accepts a gap. The stand-in rules only that no further change is made; the
   Owner's confirmation of the removal itself is still the Owner's to give.
7. **Task 3.5 item 3, gate name in `qa-autofix.yml`: option (a), keep `QA gate (read-only qa)`
   and correct the header to that exact text.** A documentation correction with no change to what
   runs. Options (b) and (c) rename a check that consumers' branch protection matches by exact
   text, so an existing consumer's required check would never report; (c) also gives two templates
   one check name.
8. **Issue 344, a configuration change cannot finish its required check: option 1, a longer limit
   only for a run that needs the full mutation run.** It keeps the gate exactly as it is. Option 2
   moves the full run out of the required check, which weakens the gate for the changes a diff run
   cannot judge; option 3 is new pipeline machinery and stays open as the long-term shape; option 4
   waited on issue 303, which is closed. Conditions: the full run's real duration is measured
   before a number is chosen (it has not been reproduced), the longer limit applies only where the
   changed paths make the lane choose a full run, and the 45 minute limit is unchanged otherwise.
   It changes the consumer template too, so it carries a changelog entry.

## DECLINED

01. **(a) The `*.secret*` guard narrowing for the two zizmor pages.** A guard that says to ask the
    user; only a human narrows it. Pull requests 37 to 40 stay blocked on it.
02. **(b) The first actions-qa-ci release, and `release.yml` with its `dangerous-triggers`
    exception (design §14.9).** A release is reserved, and the exception is a suppression. Also held
    by it: `install.sh` filled in, the composite action, `versionPin`, Task 4.4.
03. **Task 1.4, conformance reading.** Neither option changes code. It is a statement of
    conformance made in the Owner's name, on a reading the design itself says to put to the
    specification's author. `dbf.json` already declares the reading as a known gap until the Owner
    confirms, and no slice is blocked by it.
04. **Task 1.6, default strictness.** Option A (zizmor `regular`, shellcheck `warning`) is, in the
    design's own words, narrower than upstream's full catalogue, so it narrows what is reported.
    Option B is the only one left, and taking it by elimination would set the standard every
    consumer receives, which design §14 item 5 calls the Owner's call. Flag: pull request 37 runs
    the `regular` persona as an unratified working default; nothing should ship treating it as
    decided. `calibrations.shellcheckSeverity` stays refused with it.
05. **Task 1.8, the 3-day cooldown** (item 3). It is a deliberate departure from php-qa-ci's
    same-day tool currency, which `CLAUDE.md` records as an Owner decision. The Owner's answer on
    the actionlint library named "the same 72 hour cooldown", which suggests acceptance, but the
    stand-in does not turn that into a ratification. By the issue's own terms the 72 hour default
    stays applied until the Owner changes it, so nothing is blocked.
06. **Task 2.2 Decision B, `persist-credentials: false`.** Option 2 records an exception, option 3
    leaves a found gap accepted, and option 1 changes `release.yml` and `update-deps.yml` where the
    agent cannot show the result without a real release or update run. That puts an unverifiable
    change on the release path. The lane stays red on `artipacked`, which is the honest state.
07. **Task 2.2, `self-repository`.** One option is a `uses:` form the agent could not find in
    GitHub's documentation; the other is two recorded exceptions. Neither is a choice the stand-in
    can make. Establishing from GitHub's documentation whether the form is supported is research
    the agent can do without a ruling.
08. **Task 2.2, extending the standing merge authorisation to Dependabot pull requests.** An
    upgrade approval, reserved.
09. **`cel-go` (design D7).** A new dependency.
10. **pinact provenance route.** Option A adds a verifier tool, option B accepts a route weaker
    than upstream offers, option C drops pinact and half of `fix` from a v1 whose goals name
    pinact. It is the same kind of decision as Task 1.5, which the Owner made personally.
11. **The dogfood corpus.** No options are posted (the question is "which repositories, and how
    they are pinned"), and the stand-in does not invent them.

Also on issue 310, not in the list handed to the stand-in, and left as they are:

- `calibrations.selfHostedRunnerLabels`: a narrowing in two of its three shapes; Owner's.
- The untrusted-input overlap (pull request 38): tied to Task 1.6. The state pushed there (both
  identifiers kept, overlap declared) loses no finding, so nothing is forced.
- The online zizmor audits: the Owner ruled on issue 292 "default-on, skipping by name when
  offline", and the agent's preferred option 2 departs from that, so only the Owner can move it.

## What this unblocks, and what it does not

Unblocked, all in php-qa-ci on `php8.5` through the normal issue loop (test first, changelog entry,
fresh verifier, pre-push battery): SHA-pinning `uses:` with a Dependabot configuration; the
`qa-autofix.yml` header correction; the issue 344 timeout after a measurement. Items 1 to 3, 5 and 6
of CHOSEN need no build, only a record.

Not unblocked: the actions-qa-ci critical path. Pull requests 37 to 40 still wait on the guard
narrowing (a), and Task 4.4 and everything release-shaped still wait on (b). The plan therefore
stays In Progress and still awaits a human for those two.
