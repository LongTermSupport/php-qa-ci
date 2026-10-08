# PR #87 verification: Plan 00020, record the PR #83 merge; Task 2.3 done

- Pull request: https://github.com/LongTermSupport/php-qa-ci/pull/87
- Branch: `chore/plan-00020-status`, base `php8.5`
- Verified head: `2832a724f7d3f84090fb500bdd449fc421f23ce6` (one commit)
- Base read: `origin/php8.5` = `f324011b2921c074591b64cdd53d71df35fd0519`
- Verifier: fresh sub-agent, read-only. Everything was read through `git show` against remote refs
  and `gh api`. The rule-listing tests were run in a throwaway worktree, since removed.

## 1. CI

`gh pr checks 87`, on head `2832a72`. The CI run `37854562625` was triggered by `push`.

- `Detect PHP Version`: pass (4s).
- `PHP QA (8.5)`: pass (27m8s).
- `QA Pipeline`: pass (2m6s, run `37854580571`).
- `Coverage Report`: skipped by its own condition. `qa.yml:317` has
  `if: github.event_name == 'pull_request'`, and this run's event is `push`. That is expected.

## 2. Scope

`git diff --stat origin/php8.5...origin/chore/plan-00020-status` gives 2 files:

- `CLAUDE/Plan/00020-tool-currency-and-phpstan-turbo/PLAN.md` (+9/-4);
- `.../JOURNAL/00020-Journal-26-10-08.md` (+28/-0).

`git log origin/php8.5..origin/chore/plan-00020-status` shows the single commit `2832a72`. The
diff matches the description. There is no code, no lock file and no secret.

## 3. Project rules

- **Changelog trailer.** The commit carries `Changelog: none — plan record only`. The project's
  watched paths (`qaConfig/qa.php` `withChangelogWatchedPaths`: `src/`, `bin/`, `configDefaults/`,
  `templates/`, `scripts/`, `git-hooks/`, `phpstorm/`, `vendor-phar/`, `vendor-bin/`, `build/`,
  `phive.xml`, `composer.json`, …) do not include `CLAUDE/Plan/`, so the trailer is correct.
- **Branch name.** `chore/` is allowed.
- **No suppression or baseline.**

## 4. Correctness: every factual claim checked

| Claim                                                                                                                | Evidence                                                                                                                                                                                                                                                                                                                                          | Holds                                 |
| -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| #83 merged as `f324011`                                                                                              | `gh api …/pulls/83` → `merged=true`, `merge_commit_sha=f324011b…`; `git log -1 f324011` = "Merge pull request #83 …"                                                                                                                                                                                                                              | yes                                   |
| Round 1 failed on B1, round 2 passed                                                                                 | `pr-83-verification.md`: "### B1 … SIGSEGV / SIGBUS [reproduced]", `VERDICT: FAIL`; `pr-83-verification-r2.md`: `VERDICT: PASS`                                                                                                                                                                                                                   | yes                                   |
| B1: staging in system temp, `rename()` across filesystems copies into the mapped `.so`                               | r1 report lines 12-18 say the same                                                                                                                                                                                                                                                                                                                | yes                                   |
| B1 red `a7b6835`, fix `ad93c26`                                                                                      | "Red: a Turbo reinstall must not rewrite the binary a running PHPStan has mapped" / "Plan 00020: replace the Turbo binary atomically (PR #83 B1)"; both on `origin/php8.5`                                                                                                                                                                        | yes                                   |
| #82 part 2 red `81883eb`, fix `ea343e6` (`DetectorUnpacker`)                                                         | "Red: the dead-code detector must reach PHPStan as plain files, not a second PHAR" / "deadCode: give PHPStan the detector unpacked, never as a second PHAR"; both on `origin/php8.5`                                                                                                                                                              | yes                                   |
| 6/6 vs 0/6, warm cache in the earlier 14, 0/5 after the fix                                                          | matches the author's own #82 comment                                                                                                                                                                                                                                                                                                              | yes, as the author's own measurements |
| Round 2 measured 0 of 6                                                                                              | r2 report line 236: "before the fix 2 of 4 cold runs hit internal errors, after it 0 of 6"                                                                                                                                                                                                                                                        | yes                                   |
| Follow-ups #84, #85, #86                                                                                             | `gh api`, all open issues: #84 "ShellCheckInstaller replaces … in place across filesystems"; #85 "DetectorUnpacker cannot recover …, never prunes, and its lane check was never seen failing"; #86 "no detector for a second PHAR reaching a forking (Turbo) PHPStan …". These match the journal's descriptions and r2 notes                      | yes                                   |
| #52 closed as superseded by #70                                                                                      | #52: closed, not merged; its comment "Superseded by #70, merged as `ea0d245` …"; #70 closed (merged)                                                                                                                                                                                                                                              | yes                                   |
| Task 2.3: rule-listing and agent-summary tests pass on 2.3.0                                                         | `phive.xml` on `php8.5`: phpstan `installed="2.3.0"`; CI on `f324011` `success`. **Reproduced**: `ActiveRulesListerTest`, `ActiveRulesListerSelfCheckTest`, `ActiveRulesListerRenderTest`, `AgentContextIsCurrentTest` and `ActiveDefencesSummaryTest` pass, 19 tests (with Composer plugins enabled, so the extension installer's config exists) | yes                                   |
| D2 note: a phar update before the matching turbo-ext release leaves the manifest old and preflight refuses every run | `TurboInstaller::refreshManifest()` keeps the manifest when the release cannot be read (line 106: "WARNING: could not read … left as it is"). `PharToolsVerifier::verifyTurboManifest()` throws `turboManifestMismatch` (line 63-64). `PharToolsVerifier` is wired into every run via `QaApplication` line 237                                    | yes                                   |
| Release PR #80                                                                                                       | `gh api`: #80 open, "Release 85.6.0"                                                                                                                                                                                                                                                                                                              | yes                                   |
| Upstream draft at `untracked/scratch/upstream-turbo-fork-guard-draft.md`                                             | exists in the main working tree                                                                                                                                                                                                                                                                                                                   | yes                                   |
| Tasks 3.4, 3.6, 4.1 still open                                                                                       | PLAN.md lines 100, 105, 111 are `[ ] ⬜`                                                                                                                                                                                                                                                                                                          | yes                                   |

**Journal entry provenance.** The new heading is
`## 22:36 · action · —   — PR #83 merged; B1 and #82 part 2 fixed before it`. That is
byte-for-byte the shape `mkplan.bash` builds: line 457 `heading="## $journal_time · $category · $ref"`
then `"$heading   — $entry_title"`, with a UTC `date -u +%H:%M` stamp. 22:36 agrees with the
commit time `2026-10-08T22:36:42Z`. It matches the previous mkplan-written entry at 18:45. The diff
is a pure append at the end of the day file (`+28/-0`, hunk at the file's end). Nothing above it
was touched, as mkplan's append-only contract requires. The text is a plausible mkplan body, and
nothing indicates a hand edit.

**Segfault-policy reasoning in the entry.** The SIGSEGV/SIGBUS was caused by our installer
rewriting a mapped shared object. It was reduced, attributed and defended by red `a7b6835`. Not
filing with php-src is consistent with `segfault-policy.md`, whose filing step is for engine
defects.

### Notes (non-blocking)

- **N1.** PLAN.md Task 3.2 now credits the dead-code finding to "a later deadCode run". The
  journal says "The dead-code lane then reproduced #82 part 2". Both are accurate. Neither mentions
  that #82 part 1 is still open in PR #88, so a reader of this record could take #82 as finished.
  Worth a line once #88 settles.

## 5. Mergeability

`mergeable: MERGEABLE`. The head contains the current base `f324011` and carries only its own
commit. It touches no file PR #88 changes, so the two do not conflict.

VERDICT: PASS
