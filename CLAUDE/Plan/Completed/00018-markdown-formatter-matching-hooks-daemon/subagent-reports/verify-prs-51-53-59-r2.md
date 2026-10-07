# Verification round 2: PRs #51, #53, #59

Procedure: `CLAUDE/pr-verification.md` as on `origin/chore/agent-pr-verification` (4a13c2c).
Verifier: fresh read-only sub-agent. Base read: `origin/php8.5` at 385b01a.
Context: round 1 report `untracked/agent-reports/verify-prs-51-53-59.md` (notes N1–N9), used as
context only; every conclusion below is re-derived.
**Reproduced** = I ran it; **Read** = from the diff/source only.

| PR  | Branch                                    | Head verified | Moved past brief?                                                   |
| --- | ----------------------------------------- | ------------- | ------------------------------------------------------------------- |
| #51 | `feature/plan-00018-markdown-format-lane` | 207d6cb       | Yes: faf9446 → be3f0eb, 207d6cb (answering N10, N11); see "Round 3" |
| #53 | `chore/agent-pr-verification`             | 4a13c2c       | No                                                                  |
| #59 | `chore/plan-00019-agent-pr-merge-gate`    | e8223bd       | No                                                                  |

## Repository settings (reproduced, live)

- GraphQL `refUpdateRule` for php8.5: `requiredApprovingReviewCount` 0, required checks
  `["QA Pipeline"]`.
- `rules/branches/php8.5`: ruleset 18472656 (deletion, non_fast_forward, pull_request) and 23328934
  (deletion, non_fast_forward).
- All three, after CI concluded: `reviewDecision` empty, `mergeable` MERGEABLE,
  `mergeStateStatus` CLEAN.

## CI (reproduced, polled every 4 minutes until every check concluded)

| PR  | Head    | Detect PHP Version | PHP QA (8.5)  | QA Pipeline  | Coverage Report |
| --- | ------- | ------------------ | ------------- | ------------ | --------------- |
| #51 | 207d6cb | pass               | pass (25m16s) | pass (2m16s) | skipped         |
| #53 | 4a13c2c | pass               | pass (25m23s) | pass (2m48s) | skipped         |
| #59 | e8223bd | pass               | pass (24m55s) | pass (2m45s) | skipped         |

`Coverage Report` is skipped by its own condition, `if: github.event_name == 'pull_request'`
(`.github/workflows/qa.yml:317`); these runs are `push` events, so the skip is expected. #51's
faf9446 had also passed `PHP QA (8.5)` (17m17s) before the push to 207d6cb. The `changelog` lane
runs inside `PHP QA (8.5)`, so its pass on 207d6cb covers the range (207d6cb has no trailer, but
the lane is range-level and the Unreleased entry was gained since the base).

## Mergeability (reproduced)

Each branch is 0 behind `origin/php8.5`. `git merge-tree` of php8.5 + #59, then + #53, then + #51
(the stated order) is clean at every step. #51 and #59 both touch `CLAUDE/Plan/README.md` in
different hunks and merge cleanly.

## #51 — markdownFormat lane, at faf9446

### Scope (read)

The three new commits are what the brief says: e677bcc tests only; 24384c1
`MarkdownFormatTool.php`, `docs/tools/markdownFormat.md`, CHANGELOG entry; faf9446 Plan 00018
PLAN.md Task 3.4, `CLAUDE/Plan/README.md` row, and one journal heading whose only change is three
spaces to one between two em dashes (byte-checked). PR body now describes the PR as delivered (no
"merge #49 first"). Nothing unrelated, no debug output, no secrets, no lock file.

### Project rules

- **DBF ordering (reproduced).** At e677bcc (red) with e677bcc's own `src/`,
  `MarkdownFormatToolTest` fails 10 of 13 tests, including the three new ones
  (`aPathGitIgnoresIsDroppedAndNamed`, `withEveryPresentPathIgnoredTheLaneSkips`,
  `aGitProbeThatCannotRunIsACrash`). At faf9446 it passes 13/13 (41 assertions).
- **Changelog (read).** 24384c1 changes `src/` and updates the existing Unreleased › Added entry in
  the same commit; faf9446 touches only plan records and carries
  `Changelog: none — plan records, nothing a consumer receives`; e677bcc touches `tests/` only.
- No suppression, baseline or `ignoreErrors` entry. Branch prefix `feature/` allowed.

### Correctness of the new code

`MarkdownFormatTool.php:63-93`: drop absent paths with a `Not present, skipped:` line; run one
`git check-ignore -- <present paths>` from the project root; exit 0/1 is an answer, anything else
crashes; drop paths whose echo appears in stdout with a `Gitignored, skipped:` line; skip the lane
when nothing is left.

`git check-ignore` semantics, reproduced in a scratch repository
(`untracked/scratch/verify51-r2.bash`, git on this host), and the daemon v3.68.0's own reaction,
reproduced with `.claude/hooks-daemon/bin/hooks-daemon format-markdown --check` from that
repository with a `.claude/hooks-daemon.yaml` present (`untracked/scratch/verify51-r2-daemon.bash`):

| Case                                                      | `check-ignore` (lane's probe)                | Daemon on that path                     | Lane result                     |
| --------------------------------------------------------- | -------------------------------------------- | --------------------------------------- | ------------------------------- |
| `gen` with `gen/` ignored                                 | `gen`, exit 0                                | refuses, exit 1                         | dropped, correct                |
| `gen/` (trailing slash)                                   | `gen/`, exit 0                               | —                                       | echoed as given, dropped        |
| `./gen`                                                   | `./gen`, exit 0                              | —                                       | echoed as given, dropped        |
| `sp ace` (space)                                          | `sp ace`, exit 0 (unquoted)                  | refuses                                 | dropped, correct                |
| several paths, one ignored                                | only `gen`, exit 0                           | —                                       | correct                         |
| directory whose files are all ignored (`onlyign/*`)       | nothing, exit 1                              | exit 0 (walks, skips the files)         | kept, daemon passes; consistent |
| tracked file matching a pattern (`CLAUDE.md` force-added) | nothing, exit 1                              | exit 0 (daemon also omits `--no-index`) | kept; consistent                |
| `dök` (non-ASCII), ignored                                | `"d\303\266k"`, exit 0 (quoted)              | refuses, exit 1, `ERROR: … gitignored`  | **not dropped → crash** (N10)   |
| path outside the repo (`../x`)                            | `fatal: … outside repository`, exit 128      | —                                       | crash (N11)                     |
| project not in a git work tree                            | `fatal: not a git repository`, exit 128      | exit 0, formats normally                | **crash** (N11)                 |
| monorepo, cwd a sub-directory, `../gen docs`              | `../gen`, exit 0 (relative to cwd, as given) | —                                       | correct                         |

So the fix closes N1 for every ASCII path and the default list, and agrees with the daemon on the
directory-of-ignored-files and tracked-but-matching cases the brief asked about.

#### Note N10 (reproduced): a non-ASCII ignored path is not recognised, and the lane crashes

`MarkdownFormatTool.php:83` compares the probe's stdout lines with the configured paths, but git
quotes and octal-escapes any path with a byte above 0x7F, `"` or `\` (`core.quotePath`, default
on): `dök` comes back as `"d\303\266k"`. The ignored path is then passed to the daemon, which
refuses it (exit 1, `ERROR: dök is gitignored`), and line 108 reports a crash: exactly N1's
symptom, for that path shape. Reproduced at the git and daemon level (not through the PHP lane
end to end). Only a project-configured non-ASCII path can hit this; the defaults are ASCII. Cheap
fix: `git -c core.quotePath=false check-ignore …` (reproduced: prints `dök` unquoted), or
`--stdin -z` for full robustness (`-z` alone is rejected: "only makes sense with --stdin",
reproduced). A test with a fake runner would not catch this; one real-git test would.

#### Note N11 (reproduced at git/daemon level): no git work tree, or a path outside it, is now a crash

`HooksDaemonCliLocator::locate()` returns the daemon when it sits in the project root whether or
not a `.git` exists. In a project that is not a git work tree (a source export, an image built
without `.git`), `git check-ignore` exits 128 and `MarkdownFormatTool.php:76-80` crashes the lane,
while the daemon itself runs fine there (reproduced: exit 0) and documents that choice
(`cli.py` `_is_gitignored`: "nothing is refused when there is no git truth to refuse it by"). The
same 128 comes from a configured path outside the repository (`../shared-docs`) or inside a
submodule. The crash is deliberate and documented (`docs/tools/markdownFormat.md:54-55`, commit
message "a git that cannot answer is a crash rather than a guess"), and it is new in this PR rather
than a regression against php8.5, so it is a note, not a finding. Suggest matching the daemon:
treat "not a git repository" as "nothing ignored" (git's own truth is absent, so the daemon will
not refuse either), and keep the crash for other 128s.

#### Tests (read + reproduced)

The three new tests can fail (reproduced red at e677bcc). They exercise the lane through
`FakeProcessRunner`, so they pin the lane's reading of git's output rather than git's output
itself; the shapes they assume (exact echo, exit 1 for none) match real git for ASCII paths
(table above). `commands()` now filters the probe out by `command[0] === 'git'`, and the ignored
test asserts the probe's argv and cwd separately, so the filter does not hide the probe.

#### Round-1 notes

- N1 (gitignored default crashes): closed for ASCII paths (reproduced, above); residue is N10.
- N3 (misspelt path silent): closed; `Not present, skipped:` line, test asserts it.
- N5 (stale records): closed (PR body, Task 3.4, index row read).
- N2, N4: raised as issue #62, not re-reviewed beyond confirming the code they concern is
  unchanged.

### Round 3: be3f0eb (red) and 207d6cb (green), answering N10 and N11

Everything above was written at faf9446; this section covers the two commits after it.

**Diff (read).** `MarkdownFormatTool.php` now runs
`git -c core.quotePath=false check-ignore -- <paths>`, and a failed probe whose combined output
contains `not a git repository` means nothing is ignored; any other non-0/1 exit still crashes.
`docs/tools/markdownFormat.md` gains one sentence ("Outside a git work tree nothing is ignored,
as for the daemon."). be3f0eb changes tests only: the Small test's expected argv, the crash test
now uses an "outside repository" 128, a new Small test for the not-a-repository 128, and a new
Large test `tests/Large/Pipeline/Lane/MarkdownFormatToolGitTest.php` driving the lane through
real git (`SymfonyProcessRunner`) with a stub daemon that logs the paths it is handed.

**DBF ordering (reproduced).** In a throwaway worktree with `composer install`:

- at be3f0eb (red tests, old `src/`): Small test fails 2 (`outsideAGitWorkTreeNothingIsIgnored`,
  `aPathGitIgnoresIsDroppedAndNamed`); Large test fails 2
  (`outsideAGitWorkTreeEveryPresentPathIsFormatted`,
  `aNonAsciiPathGitIgnoresIsDroppedNotHandedToTheDaemon`, the latter on "two arrays are identical",
  i.e. `gën` reached the daemon);
- at 207d6cb: both files pass.

So the Large test can fail, and fails for the defects N10 and N11 describe, through real git.

**Git semantics of the 128 variants (reproduced, `untracked/scratch/verify51-r3-git.bash`).**

| Situation                                | git output (exit 128)                                                  | Lane at 207d6cb          | Daemon on the same path                     |
| ---------------------------------------- | ---------------------------------------------------------------------- | ------------------------ | ------------------------------------------- |
| no work tree                             | `fatal: not a git repository (or any of the parent directories): .git` | nothing ignored, formats | formats (exit 0)                            |
| `.git` file pointing at a missing gitdir | `fatal: not a git repository: /nonexistent/worktrees/x`                | nothing ignored, formats | its probe fails too, refuses nothing (read) |
| path outside the repo                    | `fatal: …: '../x' is outside repository at …`                          | crash                    | —                                           |
| non-ASCII ignored `dök`, quotePath off   | prints `dök` unquoted (exit 0)                                         | dropped                  | would refuse                                |

Both "not a git repository" spellings contain the matched substring, and in both the daemon's own
`_is_gitignored` gets no answer and refuses nothing, so lane and daemon agree.

#### Note N13 (read; not reproducible on this host): the substring match depends on git's locale

`MarkdownFormatTool.php` matches the English text `not a git repository`. git translates that
message when its catalogue is installed and the locale is non-English (German: "Kein
Git-Repository"); `SymfonyProcessRunner` sets no `LC_ALL`. On such a host, a project outside any
work tree gets the old crash back. This host has neither the de/fr locales nor `git.mo`, so I
could not reproduce it. The failure is loud (a crash naming git's message), not a wrong pass, so
it is a note: running the probe with `LC_ALL=C` (or checking `git rev-parse --is-inside-work-tree`
first) would remove it. A `detected dubious ownership` 128 (a container with a different UID) also
still crashes where the daemon refuses nothing; the same loud direction.

#### Note N14 (read): the outside-a-work-tree Large test assumes the temp dir is outside git

`outsideAGitWorkTreeEveryPresentPathIsFormatted` uses `TempDir::create()` under
`sys_get_temp_dir()`. If `TMPDIR` sits inside a git work tree, the test exercises the in-repo path
instead and still passes, so it would stop proving the 128 branch without failing. CI and this host
use `/tmp`; setting `GIT_CEILING_DIRECTORIES` for the probe is not possible without changing the
lane, so an assertion that `git rev-parse` fails in that directory would make the precondition
explicit. Cosmetic.

**Changelog (read + CI).** 207d6cb changes `src/` with no new CHANGELOG line and no trailer; the
lane judges the range, the existing Unreleased › Added entry for the new lane was gained since the
base, and `PHP QA (8.5)` (which runs the `changelog` lane) passed on 207d6cb. The behaviour fixed
was never released, so no further entry is owed.

**Issue #64** (DeploySkillsCharacterisationTest `removeTree()` and a symlink in copied
`untracked/`): not exercised by anything I ran and not touched by #51; no finding here.

### Verdict #51

**PASS WITH NOTES** at **207d6cb**. N10 and N11 are fixed and proven through real git. Remaining
notes N13 (locale-dependent substring; loud failure) and N14 (test precondition implicit), neither
blocking.

## #53 — CLAUDE/pr-verification.md, at 4a13c2c

### Scope / rules (read)

4a13c2c adds one sentence naming `protect-default-branch` (deletion and force-push only), which
matches the live `rules/branches/php8.5` (reproduced). Trailer
`Changelog: none — agent procedure document, nothing a consumer receives`; `CLAUDE/` and
`CLAUDE.md` are not in `withChangelogWatchedPaths` (`qaConfig/qa.php:56`). Branch `chore/`
allowed. Two files total against php8.5.

### Correctness (read, settings reproduced)

- Settings section matches live state: 0 approvals, checks kept, both php8.5 rulesets, no php8.4
  ruleset (round 1 reproduced php8.4; php8.5 re-reproduced now).
- N6: closed. N7: the PR body now states Option A and that the unattributed option concerns only
  Copilot pull requests; consistent with the document.
- N8 (links into Plan 00019 resolve only after #59) is issue #63; the links target
  `Plan/00019-agent-pr-merge-gate/PLAN.md` and `research-github-unattributed-changes.md`, both
  present on #59's head. Merge order in the body (after #59) still holds.

#### Note N12 (read): the PR body's PASS WITH NOTES line is looser than the document

The body says "PASS WITH NOTES: merge, and raise each note as an issue or a plan task"; the
document (`CLAUDE/pr-verification.md`, "What the merging agent does") says first fix any note that
is cheap and in scope (then re-verify), and raise the rest. The merge commit carries the body, so
aligning it is worthwhile; the document itself is right.

### Verdict #53

**PASS** at 4a13c2c. N12 is resolved: the coordinator reports the PR body was edited (no push),
and the current body reads accordingly. Merge after #59 (issue #63).

## #59 — Plan 00019 records, at e8223bd

### Scope / rules (read)

e8223bd ticks Task 2.2 with a "Confirmed" paragraph and annotates Task 2.3 as landing with #53;
trailer `Changelog: none — plan records, nothing a consumer receives`. Six files under
`CLAUDE/Plan/`. Branch `chore/` allowed.

### Correctness

- Task 2.2's confirmation matches live GraphQL (0 approvals, checks kept; reproduced for php8.5)
  and the empty `reviewDecision` on #51, #53, #59 (reproduced).
- Title and body no longer say "Owner decision pending"; they state Option A. N9 closed. Task 2.3
  stays unticked until #53 merges, which the plan now says.

### Verdict #59

**PASS** at e8223bd.
