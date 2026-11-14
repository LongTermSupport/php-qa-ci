# Verification of PR #440: Name the real gate check in the autofix template header

Verified head: 1db9b1423c5497cbb3dbe61887a0cd2d5fe59030 (base php8.5, three commits ahead: 2f0f0874 red test,
8902c296 fix, 1db9b142 assertion form). Worked read-only in
/workspace/untracked/worktrees/autofix-gate-name; the only files written are two scratch outputs under its
untracked/scratch/ and this report.

## Verdict: PASS WITH NOTES (CI still pending, so the merge must wait for it)

## 1. Is the claim true, and does anything else disagree?

True.

- origin/php8.5 `templates/github-actions/qa-autofix.yml:31` read `require the "gate" check`.
- The `gate` job is at `qa-autofix.yml:301-302`: id `gate`, `name: QA gate (read-only qa)`. GitHub reports a
  job's check under its `name:`, and branch protection matches the exact text, so "gate" matches no check.
  The other job names are `Branch is up to date with its base` (:119) and `Auto-fix ... [inline barrier]` (:144).
- Searched git-tracked docs/, scripts/, README.md, src/, tests/, .github/ and CLAUDE/\*.md for the autofix gate
  check name. Nothing else names it differently, so nothing disagrees now.
  - docs/github-actions.md:122-186 (autofix section) describes the `gate` job by id and gives no required-check
    name. :344 and :116 name `QA Pipeline`, which is the php-qa-ci.yml template's check, a different template.
  - scripts/setup-branch-protection.bash:16 concerns the php-qa-ci.yml job.
  - README.md:399 says "a `gate` job", which is the job id, not a check name.
- Observation, not a defect of this PR: no document tells an autofix-template consumer the exact required-check
  text except the template header (now correct). The docs' "required check" guidance covers only the
  `QA Pipeline` job. Optional follow-up.

## 2. Is the test a real detector?

Yes.

- Red commit 2f0f0874 adds only the test; `git diff 2f0f0874^ 2f0f0874 -- templates` is empty. At that commit the
  header still read `require the "gate" check` (line 31).
- The test builds `require the "QA gate (read-only qa)" check`, reading the name from the `gate` job's `name:`
  through WorkflowNode, and requires it in the file text. The old header does not contain that string, so the
  test fails at 2f0f0874 and on origin/php8.5. I reasoned this and confirmed the old header text by `git show`;
  I did not execute the red state (read-only, no checkout of another commit).
- At head the four named test files pass: `XDEBUG_MODE=coverage bin/phpunit --no-coverage --fail-on-risky ...`
  gave `OK (19 tests, 62 assertions)`, exit 0.
- The only change between the red test and head is 1db9b142: `assertTrue(str_contains(...))` became
  `assertStringContainsString`, which is stronger (the failure message shows both strings) and matches the
  fixer's preference. Behaviour is the same.
- Conventions match the sibling tests (StalePullRequestRunsTest and others): `declare(strict_types=1)`,
  namespace `LTS\PHPQA\Tests\Small`, `#[CoversNothing]`, `#[Small]`, `#[Test]`, `final`, `@internal`, a
  "Defence for the class ..." docblock, `WorkflowNode::ofFile`, `\Safe\file_get_contents`, a `private const string`. The null-name guard makes it fail loudly if the job loses its name.
- Weakness, minor: it proves the phrase is present, not that no stale second mention exists. The template has
  only one such line, so this is not a gap today.

## 3. Only a comment changed?

Yes. `git diff origin/php8.5...HEAD -U0 -- templates` is exactly one comment line
(`qa-autofix.yml:31`); the `name: QA gate (read-only qa)` at :302, the job ids and the `if:` conditions are
untouched.

- Drift checks: `VersionPinsCheck.php:163` compares only php-qa-ci.yml with .github/workflows/qa.yml.
  GitHubActionsTemplatesTest.php:77 and :87 compare release.yml and approve-held-ci/action.yml with their
  templates. The repository has no copy of qa-autofix.yml to drift from, and ShippedTemplatesRunEveryPhaseGroupTest
  and StalePullRequestRunsTest (both run, green) read other parts of the file.

## 4. CHANGELOG

- The entry is the last bullet of `### Fixed` (CHANGELOG.md:52) under `## Unreleased` (:26), at the end of the
  Fixed list (lines 81-85), before `## 85.18.1`.
- Accurate against the diff: header-only change, name unchanged, test added. "Which no job reports" is true.
  It also notes (in the PR body) that consumers who copied the template earlier see the fix only on re-copy.
  The changelog entry omits this caveat; it is not needed.
- `QA_READONLY=1 useChangelogCheck=1 GITHUB_BASE_REF=php8.5 bin/qa -t changelog` exited 0:
  `"## Unreleased" is valid: 8 entries, the next release bumps the minor` and `1 watched file changed; recorded by 1 new "## Unreleased" entry`.

## 5. PR description versus diff, and CI

- Description matches the diff. Test red in 2f0f0874, fix in 8902c296, assertion form in 1db9b142. The
  "only a comment changes" and "job name unchanged" claims hold. The claim that the stand-in made the choice
  matches Plan 00025 Task 3.5 (PLAN.md:135-145), which lists the gate name as an open Owner decision.
- Nothing in the diff goes beyond the description: three files, no docs, script, or job-name change, no
  suppression, no baseline, no lock file, no debug output.
- Branch name `bugfix/autofix-template-gate-name` is within the repository's branch policy pattern.
- The PR does not edit PLAN.md Task 3.5, which stays open as an Owner decision with the stand-in's ruling in the
  journal. That is consistent with the PR saying a later Owner message overturns it.
- statusCheckRollup (read at verification time): one entry, `QA Pipeline` (workflow "PHP QA Pipeline"),
  status IN_PROGRESS, conclusion empty, started 2026-11-14T03:31:15Z, on the head 1db9b142. mergeStateStatus is
  BLOCKED (the required check is pending), mergeable is MERGEABLE, there are no comments and the review
  decision is empty. CI has NOT concluded success; per pr-verification.md the merge waits for it.

## Notes (non-blocking)

1. CI pending: merge only after `QA Pipeline` concludes success on 1db9b142.
2. The choice of "keep the job name, fix the comment" is the stand-in's, not the Owner's; it is reversible
   by a later Owner decision (renaming the job would break consumers whose protection matches the current text).
3. Optional follow-up: docs/github-actions.md does not state the autofix gate's required-check name.
