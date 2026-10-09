# PR #90 verification — Plan 00020 housekeeping after #88

- PR: https://github.com/LongTermSupport/php-qa-ci/pull/90, branch `chore/plan-00020-after-88`
- Verified head: `54f4d1c18ff7de902eaa28349a5b1dc51c1c11f1` (the current head per `gh pr view`)
- Base read: `origin/php8.5` = `2cf8df2fe4faad871135e6f95fb37a5b81e52f81`, which is the head's merge base.
  The branch is up to date with its base.
- Verifier: a fresh sub-agent, read-only towards the PR. The lanes were reproduced in a throwaway
  detached worktree, `untracked/worktrees/verify-90`, which has since been removed.

## 1. CI

The check runs on `54f4d1c` (`gh api .../commits/54f4d1c.../check-runs`, read after every run finished):

| Check              | Conclusion | Note                                                                                                                    |
| ------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------- |
| Detect PHP Version | success    |                                                                                                                         |
| PHP QA (8.5)       | success    |                                                                                                                         |
| QA Pipeline        | success    |                                                                                                                         |
| Coverage Report    | skipped    | Expected. `.github/workflows/qa.yml:317` has `if: github.event_name == 'pull_request'`, and these runs came from a push |

When verification started, PHP QA and QA Pipeline were still in progress. The verdict waited for
them to finish.

## 2. Scope

`git diff origin/php8.5...HEAD` touches 3 files, in a single commit (`54f4d1c`):

- `CHANGELOG.md`: +4/−1, extending the existing Unreleased Turbo entry under `### Added`
- `CLAUDE/Plan/00020-tool-currency-and-phpstan-turbo/PLAN.md`: +5/−1, the Task 3.2 and Task 4.1 notes
- `CLAUDE/Plan/00020-tool-currency-and-phpstan-turbo/JOURNAL/00020-Journal-26-10-09.md`: a new file holding one entry

The diff matches the PR body. There is no code, lock file, debug output or secret in it.

## 3. Project rules

- **Changelog lane** \[reproduced\]: `GITHUB_BASE_REF=php8.5 QA_READONLY=1 CI=true bin/qa -t cl` exited 0. It reported:

  - `"## Unreleased" is valid: 5 entries`
  - the range runs from the merge base `2cf8df2`
  - `3 files changed, none of them watched: no entry needed`

  The edit itself stays inside an existing `### Added` entry, so no heading changes.
  A first run in the detached worktree without `GITHUB_BASE_REF` failed with "HEAD is detached". That is an artefact of my checkout, not of the PR.

- **Markdown format**: `bin/qa -t mdf` skipped in the throwaway worktree because no hooks daemon was present. Calling the main tree's daemon on those paths was refused because they are gitignored. So daemon-format conformance was **not independently reproduced**. To the eye, the added lines wrap at the same width as their neighbours, and CI does not run this lane. The coordinator's local gate is the evidence for this point.

- **Suppressions and baselines**: none. **DBF**: not applicable, because the PR changes no code.

- **Branch**: `chore/` is an allowed prefix.

## 4. Correctness of the claims

**CHANGELOG** \[reproduced or read\]:

| Claim                                               | Evidence                                                                                                                                                                                                                                                                                                                                          |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "about half the time (about 20 s to 9 s)"           | Matches journal 26-10-08 11:46 (T3.5): runs of 19.61/20.06/21.54 s off against 9.60/10.60/7.96 s on. PLAN Task 3.5 gives 20.4 s → 9.4 s, and `docs/tools/phpstan.md:85-86` says "about 20 s … about 9 s"                                                                                                                                          |
| "identical findings"                                | The journal says "No errors" both ways, and `docs/tools/phpstan.md:86` says "identical findings"                                                                                                                                                                                                                                                  |
| "peak memory about 6% higher"                       | The journal gives worker peaks of 163.77→175.77, 165.77→173.77 and 167.77→171.77 MB (about 4.9%), and its summary says "about 6%". This matches `docs/tools/phpstan.md:86` and PLAN 3.5                                                                                                                                                           |
| "4G by default, `withMemoryLimit()`"                | `src/Pipeline/Config/QaConfigBuilder.php:140` has `?? '4G'`, and line 170 has `withMemoryLimit(string)`                                                                                                                                                                                                                                           |
| "`diagnose` says whether Turbo loaded, and why not" | Running `php vendor-phar/phpstan.phar diagnose` printed `Turbo extension: enabled (version 6351afb)` and the loaded `.so` "(loaded via process restart)". A copy of the phar with no `turbo-ext/` beside it printed `Turbo extension: not loaded` and `Turbo worker binary: none found`, plus a "Reason fork not used" line. So the claim is true |

The measured figure is "about 20 s → 9 s", which the entry calls "about half". That is a fair description of 46%.

**PLAN.md and journal** \[read via gh and git\]:

- #88 is MERGED, with merge commit `2cf8df2fe4faad871135e6f95fb37a5b81e52f81`. Its title concerns #82 part 1. `2cf8df2` is on `origin/php8.5`.
- The three rounds hold up:
  - The round commits `70b30e5`, `93a99fb` and `493376b` exist and are ancestors of `2cf8df2`.
  - The committed reports `subagent-reports/pr-88-verification{,-r2,-r3}.md` exist.
  - The round-2 report names the `Found 1000+ errors` and project `errorFormat` shapes. The round-3 report closes them.
  - This matches the journal's account of each round.
- #89 is OPEN and titled "non-blocking notes from the pre-merge verification of #88", which matches "its non-blocking notes are #89".
- #80 is OPEN ("Release 85.6.0", `chore/release-php8.5`), which matches "(#80) is open".
- "PHPStan 2.3.0 shipped in 85.5.0" holds: tag `85.5.0` has `CHANGELOG.md` line 32, "phpstan 2.2.16 → 2.3.0".
- #82 is still OPEN. Its last comment, at 01:01Z, records part 1 merged in #88 and part 2 in #83. PLAN does not claim #82 closed.
- The journal's note on `bin/turbo-install` holds: `vendor-phar/turbo-ext/` is gitignored (`.gitignore:35`) and `bin/turbo-install` exists. A worktree therefore lacks the binary until it is installed.
- **Journal format**: the header is byte-identical to the mkplan-scaffolded 26-10-08 file apart from the date line and the first entry. The entry heading `## 01:02 · action · —` follows the grammar. The commit time of 01:03:09 UTC is consistent with an mkplan stamp. The scaffold carries the `_Scaffolded by mkplan.bash` sentinel.

## 5. Mergeability

`mergeable: MERGEABLE`. After CI finished, `mergeStateStatus` was UNSTABLE while checks were still pending, and was observed no later. There is one commit of the PR's own, and the base is the merge base.

## Notes (non-blocking)

- N1: Markdown daemon-format conformance of the three files was not reproduced by the verifier (see section 3).

## Blocking findings

None.

PASS
