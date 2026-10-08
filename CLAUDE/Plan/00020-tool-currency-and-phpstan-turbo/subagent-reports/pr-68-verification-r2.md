# PR #68 verification, round 2

- **Verdict:** PASS WITH NOTES
- **Verified head:** `8e2a29735275594400b185b289f3d3395e808774` (`bugfix/php84-phpstan-replace-pin`)
- **Base:** `origin/php8.4` at `9960f9756213fda0558498207cb96c5ddd4adf0a`, which is also the merge base
- **Previous round:** `untracked/agent-reports/pr-68-verification.md` (PASS WITH NOTES on `926aa2a`)
- **Procedure:** CLAUDE/pr-verification.md. I only read. I made no checkout and did not run the pipeline.

## 1. Head and CI: pass

`gh pr view 68` reports the head as `8e2a297`. CI run 37783214116 (CI workflow) ran on that head:

- `ShellCheck (severity=warning)`: COMPLETED SUCCESS
- `QA Pipeline`: COMPLETED SUCCESS. It was still in progress when I first checked; I polled until it finished.

After CI finished, `mergeStateStatus` was CLEAN and `mergeable` was MERGEABLE. The PR has 0 comments.

## 2. The new commit `8e2a297` is comments and prose only: pass

`git show 8e2a297` touches only two files:

- `src/ComposerPlugin/PhpStanGuardPlugin.php:18-20`: the class docblock only, with no code lines changed. It now says that phpstan/phpstan is replaced at the phar's exact version and that Composer resolves only extension releases the phar can load.
- `README.md:102`: one paragraph is rewritten.

The prose matches the code:

- `composer.json:32` has `"phpstan/phpstan": "2.2.3"`.
- `phive.xml:4` has `installed="2.2.3"`.
- The test asserts that the replace equals phive's installed version, so "the phar's exact version" is correct.
- "Composer installs only PHPStan extension releases the phar can load" matches what round 1 reproduced: phpstan-phpunit 2.0.18 and strict-rules 2.0.12 were installed instead of 2.1.x.
- "an extension release needing a newer PHPStan gets a Composer resolution error" matches the strict-rules `^2.1` failure reproduced in round 1.

Round 1 notes 1 (stale docblock) and 2 (README wording) are resolved.

### Note (non-blocking): one clause is too broad

`README.md:102` says "A project that requires `phpstan/phpstan` itself ... gets a Composer resolution error". That is true only when the project's constraint excludes the phar's version. Round 1 reproduced a root requirement of `phpstan/phpstan: ^2.0` resolving fine, while `^2.3` failed. A more exact wording would be "requires a `phpstan/phpstan` the phar does not satisfy". PhpStanGuardPlugin still warns about any root phpstan requirement, so the overstatement does no harm. I am not asking for a change before merge.

## 3. Nothing else changed since `926aa2a`: pass

- `git diff --stat 926aa2a origin/bugfix/php84-phpstan-replace-pin` shows only `README.md` (1 line) and `PhpStanGuardPlugin.php` (+3/-2).
- The branch has exactly three commits over the base: `d4d8db2`, `926aa2a` and `8e2a297`.
- The PR's file list is the round-1 three (`composer.json`, `composer.lock`, the test) plus these two.

## 4. Mergeability: pass

- `origin/php8.4` has not moved (`9960f97`) and equals the merge base, so the branch is up to date.
- `git merge-tree --write-tree` is clean.
- `mergeStateStatus` is CLEAN.

## Carried forward from round 1 (unchanged)

- Note 3: the test reads `phive.xml` rather than the phar binary. This is acceptable.
- Note 4: on php8.4, the `update-deps` workflow needs a manual bump of the replace after a phar bump.
- Recommend telling affected consumers on #60 that recovery needs `composer update lts/php-qa-ci -W` (or a full update).
