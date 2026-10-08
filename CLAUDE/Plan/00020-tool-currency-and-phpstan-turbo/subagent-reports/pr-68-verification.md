# PR #68 verification — php8.4: replace phpstan/phpstan at the shipped phar's version (#60)

- **Verdict:** PASS WITH NOTES
- **Verified head:** `926aa2a1353b92c6c927c193e8700f0ddaf3fb2a` (`bugfix/php84-phpstan-replace-pin`)
- **Base read:** `origin/php8.4` at `9960f9756213fda0558498207cb96c5ddd4adf0a` (the PR's merge base, so not behind)
- **Procedure:** CLAUDE/pr-verification.md. Read-only towards the PR. I reproduced in a throwaway worktree (`untracked/worktrees/verify-68`, now removed) and in scratch consumers under `untracked/scratch/verify-68/`.

## 1. CI — pass (read)

`gh pr view 68` and `gh run list --commit 926aa2a`: one `CI` run (pull_request), conclusion success.

- `ShellCheck (severity=warning)`: SUCCESS
- `QA Pipeline`: SUCCESS

These are exactly the two required contexts in php8.4's update rule (`requiredStatusCheckContexts`: "QA Pipeline", "ShellCheck (severity=warning)"; `requiredApprovingReviewCount` 0).

`qa.yml` is a consumer template that triggers only on main/master/develop/feature/fix branches, and `update-deps.yml` runs on schedule or dispatch only. Neither triggering is expected. `mergeStateStatus` CLEAN, `reviewDecision` empty, no comments or reviews on the PR.

## 2. Scope — pass (read)

The diff is 3 files, matching the description:

- `composer.json:32`: `"phpstan/phpstan": "*"` becomes `"2.2.3"`.
- `composer.lock:7`: the `content-hash` changes and nothing else. I reproduced the check that this is the hash Composer itself writes: `composer validate` raises no lock-staleness warning, and `composer install` from the lock in the worktree ran with no "lock file is not up to date" warning. It is not a hand-edited lock.
- `tests/Small/PhpstanReplaceMatchesPharTest.php` (new).

There is no debug output, no secrets and nothing unrelated.

## 3. Project rules — pass

- **Defence Before Fix ordering (reproduced).**
  - At `d4d8db2` (red commit) the new test fails: `-'2.2.3' +'*'`, at `PhpstanReplaceMatchesPharTest.php:34`.
  - At `926aa2a` it passes (1 test, 4 assertions).
  - It is in the shipped suite: `qaConfig/phpunit.xml:17` includes `../tests/Small`, and `--list-tests` lists it.
- **No new suppression, baseline or ignoreErrors** (read).
- **Changelog:** php8.4 has no `CHANGELOG.md` and no changelog lane (confirmed `git cat-file -e origin/php8.4:CHANGELOG.md` fails), so neither an entry nor a trailer applies.
- **Branch name:** `bugfix/` is an allowed prefix.
- **php8.4 backport policy (php8.5 CLAUDE.md "Work happens on php8.5"): satisfied.**
  - #60 is a report from a project consuming `dev-php8.4@dev` (php-qa-ci `0f4179f`), with an exact reproduction.
  - The reporter ran PHP 8.5.10, not 8.4. That does not matter: the defect is a package-version mismatch, not a runtime one.
  - My reproduction used `config.platform.php` 8.4.13, and CI runs PHP 8.4.
  - This is the "a php8.4 project reports that bug" trigger, not a proactive backport.

## 4. Correctness — pass, with notes

### 4a. A versioned replace does cap the extensions (reproduced)

I built scratch consumers with `require-dev: lts/php-qa-ci dev-php8.4@dev` through a path repository and `platform.php` 8.4.13, then ran `composer update --no-install --no-plugins --no-scripts`.

| Consumer                                            | phpstan-phpunit               | phpstan-strict-rules   |
| --------------------------------------------------- | ----------------------------- | ---------------------- |
| Against the PR head (replace `2.2.3`)               | 2.0.18 (needs phpstan ^2.2.3) | 2.0.12 (needs ^2.1.52) |
| Against `origin/php8.4` composer.json (replace `*`) | 2.1.1                         | 2.1.0                  |

The `*` row reproduces #60 exactly. The `2.2.3` row matches the PR body.

I also tested a consumer that adds the suggested or common extensions unconstrained (`phpstan-symfony`, `phpstan-doctrine`, `phpstan-deprecation-rules`, `phpstan-mockery`, `larastan/larastan`). Everything resolved to releases whose `phpstan/phpstan` requirement 2.2.3 satisfies:

- larastan v3.12.0 (^2.2.2)
- doctrine 2.0.28 (^2.2.2)
- symfony 2.0.20 (^2.1.13)
- deprecation-rules 2.0.5 (^2.1.39)
- mockery 2.0.0 (^2.0)

The phar in the tree reports `PHPStan - PHP Static Analysis Tool 2.2.3`, which agrees with `phive.xml:4` `installed="2.2.3"`.

### 4b. Consumers that worked before and break now (reproduced; the failure is understandable)

- **Root requires `phpstan/phpstan: ^2.0`:** still resolves, because the replace satisfies it.

- **Root requires `phpstan/phpstan: ^2.3`:** resolution fails (exit 2) with:

  > Only one of these can be installed: phpstan/phpstan[2.3.0], lts/php-qa-ci[dev-php8.4]. lts/php-qa-ci replaces phpstan/phpstan and thus cannot coexist with it.

  Under `*` the same consumer resolved "successfully" while really running the 2.2.3 phar. So this turns a silent mismatch into a loud one. The message names php-qa-ci as the replacer, but not the reason (the phar's version).

- **Root requires `phpstan/phpstan-strict-rules: ^2.1`:** fails (exit 2). The chain is shown in full: `strict-rules 2.1.0 requires phpstan/phpstan ^2.3`, then "lts/php-qa-ci replaces phpstan/phpstan and thus cannot coexist with it". This is understandable.

- **A consumer already broken by #60** (lock holding the 2.1.x extensions) **running `composer update lts/php-qa-ci` without `-W`:** fails (exit 2) with the strict-rules 2.1.0 chain. Composer 2.10.3 printed no "use -W" hint.

  - `composer update lts/php-qa-ci -W` downgrades to phpunit 2.0.18 and strict-rules 2.0.12, which is the fix.
  - #60's reproduction steps already used `--with-all-dependencies`, so the reporter's own route works.
  - The issue comment on #60 does not tell an affected consumer to use `-W` (or a full `composer update`) to recover. That is worth adding when the merge is announced on #60.

### 4c. Notes (non-blocking)

1. **Stale docblock, `src/ComposerPlugin/PhpStanGuardPlugin.php:19`.** It still says `"replace": {"phpstan/phpstan": "*"}`. Read, not executed. The plugin's logic does not depend on the value: it warns on a root phpstan requirement and compares the phar to the extension-installer constraint.
2. **README wording, `README.md:102`.** "This prevents version conflicts when consuming projects also require PHPStan extensions". After this change the replace deliberately *produces* a resolution conflict when an extension needs a newer PHPStan than the phar. The sentence should say the replace pins extensions to releases the shipped phar can load.
3. **The test reads phive.xml, not the phar binary.** It compares the replace to `phive.xml`'s `installed` attribute rather than the phar's own `--version`. Today the two agree (verified: 2.2.3). A hand-replaced phar with phive.xml left untouched would slip past, but phive keeps them in step, so this is acceptable as is.
4. **Weekly `update-deps.yml` (php8.4 copy).** It runs `phive update` (which may move phpstan.phar within `^2.2.3`), but nothing moves the replace. If dispatched on php8.4 after a phar bump, the new test fails the job's QA, so no PR is opened. That is the guard working, but it needs a manual replace bump. The workflow only runs on schedule on the default branch (php8.5), so php8.4 is affected only by manual dispatch. php8.5's `bin/phpstan-replace-sync` (Plan 00020) addresses this there.

## 5. Mergeability — pass (read)

`git merge-tree --write-tree origin/php8.4 origin/bugfix/php84-phpstan-replace-pin` is clean. The branch carries only its own two commits (`d4d8db2`, `926aa2a`) on top of the base tip. The PR is not stacked.

## Out of scope (as briefed)

Infection 0.34.0 crashes on PHP 8.5 with `Infected\array_first()`. This is unrelated to the diff and not exercised. I did not run the full pipeline.
