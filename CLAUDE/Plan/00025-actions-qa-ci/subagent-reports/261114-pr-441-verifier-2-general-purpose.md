# PR #441 verification, second pass, head a0afee792a884cc9a6871b5d12667289fef98235 (base php8.5)

Verdict: PASS WITH NOTES. The only open item is that CI had not finished, so the merge waits for it.

## 1. CI (read via gh)

- `gh pr checks 441`: the single check, "QA Pipeline", was `pending` (IN_PROGRESS) when last read, run 43560901956. It is not a pass. The merge waits for it to conclude success.
- `mergeStateStatus` is BLOCKED, which is the pending required check. reviewDecision is empty and there are no PR comments.

## 2. Scope (read, git diff origin/php8.5...origin/chore/pin-actions-to-commit-shas)

- 3 commits, and origin/php8.5 is an ancestor of the head, so the branch is not stacked.
- 10 files: .github/dependabot.yml (new), .github/workflows/{qa,release,update-deps}.yml, templates/github-actions/{php-qa-ci,qa-autofix,release}.yml, CHANGELOG.md, tests/Small/WorkflowActionRuntimeTest.php, tests/Small/WorkflowActionsArePinnedToACommitTest.php (new).
- In every .github and templates file, the only changed non-`uses:` lines are the new dependabot.yml content: a comment header and a weekly github-actions config. No other workflow line changed.
- No lock file or secret is touched, and no suppression or ignoreErrors entry is added.
- CHANGELOG has an `## Unreleased` entry. It states that consumers' copies are not updated automatically.
- WorkflowActionRuntimeTest: the USES regex now also reads `@<sha> # vN`, and the guard test has a SHA-form case (an old major in SHA form is still reported). Read, not run against anything else.

## 3. Pinned SHAs against tags (reproduced, `gh api repos/<r>/commits/<tag> --jq .sha`)

All nine match the SHA in the files, and the `# <release>` comment is right in each:

- marocchino/sticky-pull-request-comment v3.0.5 = 5770ad5eb8f42dd2c4f34da00c94c5381e49af88 (note A resolved)
- actions/checkout v7 = 3d3c42e5aac5ba805825da76410c181273ba90b1
- shivammathur/setup-php v2 = eb7c497e18156a6bbabfef1d3a82760b9eda3962
- actions/cache v6 = 55cc8345863c7cc4c66a329aec7e433d2d1c52a9
- actions/upload-artifact v7 = cf430e030ddbb5b0abf93d22962f4752f3646cd9
- actions/download-artifact v8 = 9000827ccba6bdab643e8b6fd33ac0654aef8333
- actions/github-script v9 = 3a2844b7e9c422d3c10d287c895573f7108da1b3
- peter-evans/create-pull-request v8 = 3ffccacd46053766916f087ce41d627c0b8cf2d1
- irongut/CodeCoverageSummary v1.3.0 = 51cc3a756ddcd398d447c044c02cb6aa83fdae95

`git grep` over .github and templates at the head lists exactly these nine distinct `uses:` references, each as `@<40 hex> # <release>`.

## 4. Template parity (reproduced, git show + diff --no-index)

- qa.yml is byte-identical to templates/github-actions/php-qa-ci.yml.
- release.yml is byte-identical to templates/github-actions/release.yml.

## 5. Tests (reproduced in throwaway worktrees, removed afterwards)

- At head: `bin/phpunit --no-coverage WorkflowActionsArePinnedToACommitTest.php WorkflowActionRuntimeTest.php` gave OK (4 tests, 6 assertions).
- At base (origin/php8.5 with only the new detector test copied in): the detector fails with 38 unpinned references listed (tags such as actions/checkout@v7 across qa.yml, release.yml, update-deps.yml and the three templates). The second test, the guard-fires-on-tag/branch/bare-SHA proof, passes on base. So the detector catches the defect class and was not vacuously green.
- The detector reads .github (all yml, including dependabot.yml, which has no `uses:`) and templates/github-actions. It exempts `./` local actions, and a bare SHA with no `# vX` release is a finding.

## 6. Mergeability

- `mergeable` is MERGEABLE. No conflict, and base php8.5 is contained in the head. Only the pending CI check keeps it BLOCKED.

## Notes

- N1 (blocking the merge, not the verdict): wait for "QA Pipeline" to finish green on a0afee79.
- N2 (informational, not new): consumers' copies of the templates keep their tags until copied again. The CHANGELOG says so.
- This pass did not run the full pipeline. CI is running it.
