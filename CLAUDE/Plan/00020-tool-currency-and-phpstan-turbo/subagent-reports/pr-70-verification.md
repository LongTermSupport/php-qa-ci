# PR 70 verification

Pull request [#70](https://github.com/LongTermSupport/php-qa-ci/pull/70), "Plan 00020 Phases 1-2: the daily tool update lands, PHPStan 2.3.0, #60 replace pin".
`feature/plan-tool-currency-and-turbo` into `php8.5`.

- **Verified head:** `700f04290333ab7a0a5a2b89d68de1431f1288bd`
- **Base read:** `origin/php8.5` at `19e747d1610234f5e26ef5b862481d20fba06162` (the merge base, so the branch is up to date)
- **Method:** I read the diff through `git diff origin/php8.5...HEAD`. I reproduced findings in a throwaway detached worktree, `untracked/worktrees/verify-70`, after running `composer install` there. Consumer fixtures are under `untracked/scratch/`. I did not run the full pipeline; CI ran it.

Each finding below is marked **[reproduced]** (I ran it) or **[read]** (I only read the code or docs).

## 1. CI

**[read via gh]** All checks on head `700f042` have concluded:

| Check                                | Result       | Notes                                                                                                                                                                      |
| ------------------------------------ | ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `QA Pipeline` (ci.yml, pull_request) | pass, 3m5s   | The required check (`requiredStatusCheckContexts: ["QA Pipeline"]`). Read-only run. Changelog lane: `19 watched files changed; recorded by 3 new "## Unreleased" entries.` |
| `PHP QA (8.5)` (qa.yml, push)        | pass, 26m23s | The full run with coverage. Every step succeeded, and `Commit code changes (CS Fixer, Rector)` was skipped, so no fixer wanted a change.                                   |
| `Detect PHP Version`                 | pass         |                                                                                                                                                                            |
| `Coverage Report`                    | skipping     | Expected: the job has `if: github.event_name == 'pull_request'`, and this qa.yml run was a push event.                                                                     |

Merge state: `mergeStateStatus: CLEAN`, `requiredApprovingReviewCount: 0`, no PR comments. `git merge-tree` against `origin/php8.5` reports no conflicts.

## 2. Scope

**[read]** The diff (80 files) matches the description: Phase 1 (T1.1–T1.5), Phase 2 (T2.1, T2.2), #60 (T2.4), #54, plan records and verifier reports.

Two things the body does not list on their own:

- **`src/Pipeline/Lane/MarkdownFormatTool.php`:** in `cd85df1` ("the last PHPStan 2.3.0 findings"), two `foreach` loop variables are renamed so they no longer reuse `$path`. Behaviour is unchanged. The body covers this commit as "the other four findings".
- **`composer.lock`:** only the `content-hash` changed, as a result of the `replace` edit. `composer validate` on the head reports the file valid and does not warn that the lock is stale, so the hash is the one Composer computes. It was not hand-edited.

I found no debug output and no secrets.

## 3. Project rules

- **Suppressions and baselines** **[reproduced]**: I scanned the added lines (excluding phars and plan files) for `phpstan-ignore`, `ignoreErrors`, `baseline`, `@codeCoverageIgnore`, `markTestSkipped`, `markTestIncomplete`, `@infection-ignore`, `var_dump` and `print_r`. None were found. `qaConfig/phpstan.neon` is untouched.
- **Test changes inside fix commits** **[read]**: none weakens an assertion.
  - `2160764`: replaces a `(string)` cast with `assertIsString`, which makes the check stricter, and replaces `glob` with `FilesystemIterator`.
  - `7369f06`: updates the expected branch name to `chore/update-deps-qa`.
  - `d941c4e`: adds the newly recorded `nikic/php-parser (in rector.phar)` key to the expected array.
- **Defence Before Fix ordering** **[reproduced]**: I checked out each red commit and its fix commit and ran the red test at both.

| Fix  | Red commit | Red test at red commit                                                                             | At fix commit          |
| ---- | ---------- | -------------------------------------------------------------------------------------------------- | ---------------------- |
| T1.1 | `c65d197`  | `SelfBuiltPharReproducibilityTest`: 4 failures, a random Box alias in each self-built stub         | `2160764` OK (8 tests) |
| T1.2 | `6857aba`  | `ChangelogReleaseCommandTest::addToolUpdatesRecordsADependencyThatMovedInsideASelfBuiltPhar` fails | `d941c4e` OK           |
| T1.3 | `3daaa2c`  | `UpdateDepsJudgesItselfAsItsPullRequestTest` fails                                                 | `7369f06` OK           |
| T1.4 | `0f3a68f`  | `UpdateDepsRunsDailyAndReportsFailureTest` fails                                                   | `4f2d59c` OK           |
| T2.2 | `a32227e`  | `ScopeStubTraitTest`: the stub is not an instance of `PHPStan\Analyser\DependencyTracker`          | `2741e00` OK           |
| #60  | `a895522`  | `PhpstanReplaceMatchesPharTest`: the two strings are not identical (`*` against `2.3.0`)           | `ac166be` OK           |

T2.1 (the phar bump) and #54 (a test-only change) have no red commit, which is expected for both.

- **Changelog** **[read + CI]**:
  - `## Unreleased` gains a `### Changed` entry (phpstan 2.2.16 → 2.3.0) and two `### Fixed` entries: #60, and `add-tool-updates` recording dependencies inside self-built phars.
  - No released section was edited: the only CHANGELOG hunk is inside `## Unreleased`.
  - The lane passed in CI.
- **Branch name**: `feature/…` is allowed.

## 4. Correctness

### #60: replace pinned to the phar

**[reproduced]** The pin and the phar agree:

- `composer.json` has `"replace": {"phpstan/phpstan": "2.3.0"}`.
- `phive.xml` records `installed="2.3.0"`.
- `php vendor-phar/phpstan.phar --version` prints `2.3.0`.
- `vendor-docs/phpstan/version` is on 2.3.0.

**`PhpstanReplaceSync`** **[read + reproduced]**:

- Install mode only verifies, and update mode rewrites the one value with a regex scoped to the `replace` object.
- Its 6 unit tests pass. They cover install match, install mismatch with no write, update rewrite with the rest of the file byte-identical, update no-op, a missing replace, and a missing phive entry.
- `php bin/phpstan-replace-sync` exits 0 on the head.
- Any mode other than `update` is treated as verify-only, which is the safe default.
- `scripts/tool-install.bash` runs only as php-qa-ci's own root `post-install-cmd`/`post-update-cmd`, so it never runs in a consumer's install and cannot break one.

**Consumer resolve probe** **[reproduced]**: I set up a scratch project (`untracked/scratch/pr70-consumer`) with a path repository to the head, `lts/php-qa-ci` at `85.99.0`, and `*` constraints on strict-rules, phpunit and deprecation-rules. It resolved to:

- `phpstan-phpunit` 2.1.1 (requires `^2.3.0`)
- `phpstan-strict-rules` 2.1.0 (requires `^2.3`)
- `phpstan-deprecation-rules` 2.0.5 (requires `^2.1.39`)
- `type-coverage` 2.5.0 (requires `^2.2`)
- `extension-installer` 1.4.3

Every one of these accepts 2.3.0, the phar's version.

**`update-deps.yml`** **[read]**: the added step `composer update` after the PHAR rebuild re-resolves against the moved replace and refreshes the lock's content-hash. The QA step runs on `chore/update-deps-qa` with the update uncommitted, then `git switch -`; the pull request action creates `chore/update-deps` itself. `if: failure()` comments on or opens the `update-deps-failure` issue, and `issues: write` is granted.

### #54: zombie test (`700f042`)

**[read + reproduced]** The race is gone.

- **Why the old test was flaky:** the child was `true`. Symfony's `start()` and `getPid()` both call `proc_get_status()`, which reaps a child that has already exited, so the child could be reaped before it became a zombie.
- **What the test does now:**
  1. The child waits on a marker file.
  2. The test reads the pid while the child is alive, and asserts it is greater than 1.
  3. The test touches the marker.
  4. Nothing calls into the `Process` again until the assertions.
  5. The child exits unreaped and becomes a zombie.
  6. The test asserts `/proc/<pid>/stat` shows `Z`, then `ProcessTree::isAlive()` is false.
- **What it proves:** it still proves what it claims, since the zombie state is asserted directly before `isAlive()`. `tearDown`'s `getPid()` reaps the child afterwards.
- **Runs:** 25 of 25 isolated runs passed, and 60 of 60 under load (4 concurrent runs × 15, with 8 `yes` CPU burners).

### T1.1: reproducible phars

**[reproduced]** I rebuilt `composer-dependency-analyser.phar` twice with `scripts/build-phar.bash composer-dependency-analyser --force`. Both builds were byte-identical to the committed phar (sha256 `0bc3594e…`), and the worktree stayed clean.

### Other tests at the head

**[reproduced]** These all pass at the head:

- `tests/Small/PHPStan/Rules` (292 tests)
- `ScopeStubTraitTest`
- `BundledToolVersionsTest`
- `ChangelogReleaseCommandTest`
- both `UpdateDeps*` tests
- `WorkflowActionRuntimeTest`
- `PhpstanReplaceMatchesPharTest`

## 5. Notes (non-blocking)

- **N1. The re-resolve step has no GitHub token** (`.github/workflows/update-deps.yml`, step "Re-resolve the PHPStan extensions against the phar").
  - That `composer update` runs `post-update-cmd`, which is `tool-install.bash update`. That script calls phive (`phive outdated`, and a re-resolve if needed), the ShellCheck updater and the docs installer, all of which hit the GitHub API.
  - Step 1's own comment says "post-update-cmd runs the PHAR update too, so the token is needed here as well" and sets `GITHUB_AUTH_TOKEN`. The new step does not.
  - On a rate-limited runner this can fail the daily job and open a failure issue for no real reason.
  - Cheap fix: add `env: GITHUB_AUTH_TOKEN: ${{ github.token }}` to the step.
- **N2. `CLAUDE/tool-currency.md` leaves out the second `composer update`** (both "What runs on its own" and "Doing it by hand").
  - Composer writes the lock before `post-update-cmd` moves the replace. So a maintainer who follows the hand procedure after a PHPStan release gets a `composer.lock` whose content-hash is stale against the edited `composer.json`.
  - They also get extensions still resolved against the old phar.
  - The hand procedure should add `composer update` after the PHAR steps, and should regenerate CLAUDE.md with `php bin/rules . --write-agent-summary=CLAUDE.md`.
- **N3. `README.md:391` contradicts D1.** It still says update-deps "opens a PR for the owner to merge if green". Under D1 an agent session merges it after a fresh verifier's verdict, as `docs/ci.md` and `tool-currency.md` now say.
- **N4. Several `Changelog: none — …` lines are not git trailers.**
  - Affected commits include `2160764`, `7369f06` and `700f042`. Each line is separated from `Co-Authored-By` by a blank line, so `git log --format=%(trailers:key=Changelog)`, which `ChangelogGit::trailers()` uses, does not see it.
  - This has no effect here, because the branch carries `## Unreleased` entries. It would matter on a branch that relies on the trailer alone.
  - The same pattern is already common on `php8.5` (for example `a0680a5` and `10741a2`), so it predates this PR.
- **N5. The tighter replace can make Composer fail for some consumers.** The replace narrowed from `*` to exactly `2.3.0`. A consumer, or one of its dependencies, whose own `phpstan/phpstan` constraint excludes 2.3.0 now gets a Composer resolution error instead of a silent pass. That is the intended effect and the README documents it, but it is filed under `### Fixed` rather than `Changed — breaking`. In practice no realistic constraint excludes 2.3.0 today, so I agree with `Fixed`. It is recorded here so the Owner knows.

## Verdict

**PASS WITH NOTES**. Verified head `700f04290333ab7a0a5a2b89d68de1431f1288bd`, against base `php8.5` at `19e747d1610234f5e26ef5b862481d20fba06162`.
