# PR #77 verification

- PR: Plan 00020: fix the #70 verifier's notes (update-deps token, tool-currency docs)
- Head verified: `cf21b2ddea9ba028254c58b8445a48d52f2f45d3` (branch `chore/plan-00020-verifier-notes`)
- Base: `php8.5` at the time of reading (merge `cf21b2d` takes in origin/php8.5)
- Verdict: **PASS WITH NOTES** (no blocking finding)

## 1. CI (read)

All runs are on `cf21b2d`. The CI workflow's "QA Pipeline" job succeeded. In "PHP QA Pipeline" (run 37816178946, event `push`), "Detect PHP Version" and "PHP QA (8.5)" succeeded, and "Coverage Report" was skipped.

The skip is expected. The `coverage-report` job in `.github/workflows/qa.yml:317` has the condition `if: github.event_name == 'pull_request'`, and this run's event was `push`.

## 2. Defence Before Fix (reproduced)

I made a throwaway worktree at `61d6148`, ran `composer install`, and then ran `bin/phpunit -c qaConfig/phpunit.xml --no-coverage tests/Small/UpdateDepsStepsReachingGitHubCarryTheTokenTest.php`.

- At `61d6148` it is **red** for the right reason: `testEveryStepReachingGitHubSetsTheToken` reports `['Re-resolve the PHPStan extensions against the phar']`. The guard test passes.
- At `cf21b2d` both tests pass (2 tests, 49 assertions).
- The red commit precedes the fix, so the ordering is correct.
- The guard test (`testTheGuardReportsAStepWithoutTheToken`) proves the detector fires on three cases:
  - a root `composer update` with no token
  - a `tool-install.bash` step with no token
  - a `--working-dir` build update followed by a comment that mentions `composer update` and `tool-install.bash`, which must not be reported

### Heuristic probe (reproduced)

I called the private `stepsWithoutToken()` by reflection on hand-built YAML. The probe file sat under `untracked/scratch/` and has since been removed.

False negatives, where the detector reports nothing although it should:

| Input                                                                        | Result       |
| ---------------------------------------------------------------------------- | ------------ |
| `composer up` / `composer u` / `composer upgrade`                            | not reported |
| `composer require foo/bar` (runs post-update-cmd)                            | not reported |
| `composer install` (post-install-cmd runs `tool-install.bash`)               | not reported |
| `composer update --working-dir=build/x && composer update` on one line       | not reported |
| `composer  update` (two spaces), `composer --no-interaction update`          | not reported |
| `php composer.phar update`                                                   | not reported |
| Token only in a comment (`# GITHUB_AUTH_TOKEN: x`)                           | not reported |
| `GITHUB_AUTH_TOKEN:` mentioned in the run text, or set with an empty value   | not reported |
| **Steps indented 8 spaces** (e.g. after a reindent): no step is split at all | not reported |

The last row is the most significant. `STEP_START` is `/^ {6}- /m`, so if the workflow is reindented, `preg_split` yields no steps and the real test passes vacuously. Nothing asserts that at least one step was found, or that the known steps were found.

Correctly handled:

- a multi-line `run: |` block (the regex works line by line)
- a `./scripts/tool-install.bash` step
- a list item whose `run:` comes first (it is reported, though under the run text instead of a name)

A token at job or workflow level `env:` is reported as missing. That is a false positive, but a safe one: the test fails loudly rather than passing.

Neither class matters for the workflow as it stands: every step of it is detected correctly. They are notes for hardening.

## 3. Workflow change (read)

`.github/workflows/update-deps.yml:85-88`: the re-resolve step now has `env: GITHUB_AUTH_TOKEN: ${{ github.token }}`. This is identical to the first `composer update` step (lines 56-59) and the PHIVE step (lines 65-68).

`github.token` is the job's `GITHUB_TOKEN`, which is the right token for API reads that avoid the rate limit. A root `composer update` runs `post-update-cmd` → `./scripts/tool-install.bash update` (`composer.json` scripts), so the token is needed here.

No other step needs the token:

- The self-built step runs only `--working-dir` updates.
- `ci.bash` runs `bin/qa` only; it runs no composer update.

## 4. Docs vs workflow (read)

`CLAUDE/tool-currency.md:12-25` now lists these steps:

1. composer update
2. PHIVE
3. self-built PHARs
4. second composer update
5. rules-summary regeneration
6. add-tool-updates
7. QA on a work branch
8. PR

This matches the yml order at lines 56, 65, 74, 85, 94, 116, 133 and 199. The "Detect changes" gate and the summary step are omitted; that is reasonable, since they are not actions.

The manual command list (lines 75-81) now includes the second `composer update` and `php bin/rules . --write-agent-summary=CLAUDE.md`, in the same order. The command matches yml line 95.

## 5. Scope and changelog (read)

The diff is 5 files:

- the workflow
- `CLAUDE/tool-currency.md`
- `README.md`
- the new test
- the filed #70 verifier report

The merge commit adds only the `CHANGELOG.md` release heading from php8.5. There is nothing unrelated, no debug output, no secrets and no lock-file edit. The branch name `chore/` is allowed.

The changelog lane's watched paths (`qaConfig/qa.php:56-71`) do not include `.github/`, `tests/`, `CLAUDE/` or `README.md`. No watched path changed, so both `Changelog: none` trailers are justified. The README change rewords how the maintainer's own update PR is merged; no consumer behaviour changes.

## 6. Mergeability (read)

`gh pr view 77`: `mergeable: MERGEABLE`, `mergeStateStatus: CLEAN`. There were no comments.

## Notes (non-blocking)

- N1: `tests/Small/UpdateDepsStepsReachingGitHubCarryTheTokenTest.php:41` (`STEP_START`). An indentation change makes the test pass vacuously. Assert that the split found the expected steps, or at least one step that reaches GitHub.
- N2: `tests/Small/UpdateDepsStepsReachingGitHubCarryTheTokenTest.php:35` (`ROOT_COMPOSER_UPDATE`). It misses the `composer up`/`u`/`upgrade`/`require`/`install` spellings, and a line that pairs `--working-dir` with a root update. The token check (line 66) also accepts a commented-out or empty token. These are worth a follow-up issue rather than holding the merge.
