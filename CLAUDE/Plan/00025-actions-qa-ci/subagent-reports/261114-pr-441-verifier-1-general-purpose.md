# Verification of PR #441 (head 034e2ebf68d9517990da559f16c31d657dcbcb75, base php8.5)

Verdict: PASS WITH NOTES, conditional on CI. The only required check, `QA Pipeline`, was still
IN_PROGRESS (pending) at every re-check; a running check is not a pass, so the merge waits for it.
No blocking finding in the change itself.

R = reproduced (ran it), D = read (diff / API output).

## 1. CI

- `QA Pipeline` pending, started 2026-11-14T05:05:05Z. `mergeStateStatus: BLOCKED` is the pending required check
  (`requiredStatusCheckContexts: ["QA Pipeline"]`, `requiredApprovingReviewCount: 0`, `reviewDecision: null`). (D)
- No PR comments.

## 2. Scope (D)

Ten files, matching the description: `.github/dependabot.yml` (new), `qa.yml`, `release.yml`, `update-deps.yml`,
the three templates, `CHANGELOG.md`, `WorkflowActionRuntimeTest.php`, new `WorkflowActionsArePinnedToACommitTest.php`.
In every workflow/template diff the only changed lines are `uses:` lines (tag to `@<sha> # vN`). No lock file, debug output
or secret. Two commits, merge-base equals the php8.5 tip (d8fc29c0), so no stacking and no conflict (`mergeable: MERGEABLE`).

## 3. Project rules

- DBF ordering (R): checked out `ee560575` in my own throwaway worktree and ran the detector: FAILS, exit 1, 38
  "is not a commit SHA" problems. At head (034e2ebf) both new/updated test classes pass: 4 tests, 6 assertions.
  Detector commit precedes the fix commit.
- Changelog (D): entry is under `## Unreleased` / `### Changed` (CHANGELOG.md:38). The red commit carries
  `Changelog: none — a test only` and says the entry is in the next commit.
- Branch `chore/pin-actions-to-commit-shas` is allowed (`chore/`).
- No suppression, baseline or `ignoreErrors` added (D).

## 4. Correctness

SHAs (R, via `gh api repos/<r>/commits/<sha>` and `git/ref/tags/<tag>`, annotated tags dereferenced through
`git/tags/<sha>`, and `commits/<tag>`): all nine SHAs exist in the named repository and equal what the tag resolves to.

- actions/checkout v7 = 3d3c42e5 ; actions/cache v6 = 55cc8345 ; actions/upload-artifact v7 = cf430e03 ;
  actions/download-artifact v8 = 9000827c ; peter-evans/create-pull-request v8 = 3ffccacd ; irongut/CodeCoverageSummary v1.3.0 = 51cc3a75 (lightweight tags)
- shivammathur/setup-php v2 = eb7c497e (annotated tag d52fc211 dereferences to it)
- actions/github-script v9 = 3a2844b7 (annotated tag 373c709c dereferences to it)
- marocchino/sticky-pull-request-comment v3: there is NO tag `v3`; `v3` is a BRANCH (refs/heads/v3 = 5770ad5e), which is
  also tag v3.0.5. The pin is a real commit and is what `v3` resolves to, but `# v3` names a moving branch, not a release (note A).

Pinning (R): all 41 `uses:` lines under `.github/` and `templates/github-actions/` are `owner/repo@<40hex> # vN`
except three `./.github/actions/approve-held-ci` local references (exempt). `cmp` confirms qa.yml == templates/php-qa-ci.yml
and release.yml == templates/release.yml byte for byte. Only other `uses:` in tracked YAML is a test fixture
(`tests/assets/versionPins/.../static.yml`), outside the detector's scope.

Regex probing (R, scratch script run against 25 shapes; detector = USES + PINNED, runtime = updated USES):

- Flagged (correct): tag, branch, bare SHA, SHA with no comment, uppercase hex, 39/41 hex, `#v1` (no space), `# v1 extra`,
  `# v1-beta`, `# main`, `refs/tags/v1`, `${{ }}` expression, reusable workflow with tag.
- Pass (correct): SHA + `# v1`, SHA + several spaces, trailing `\r`, reusable workflow `a/b/.github/workflows/x.yml@<sha> # v1`.
- No false pass found among real `uses:` lines. Over-flags (fail-safe, not blocking): quoted `'a/b@v1'`, quoted `'./x'`
  (local path no longer exempt), `docker://...` (even `@sha256:` digest-pinned; cannot be made to pass), any `uses:` text inside a
  `run:` block.
- Gap (not blocking): flow-mapping `- {uses: a/b@v1}` is NOT seen by the detector (false pass). No workflow here uses that form.
- Runtime-floor regex: reusable-workflow references (`owner/repo/path@...`) are skipped by it, so the Node floor does not
  apply to them (none exist today). The `# vN` comment is never checked against the SHA (not checkable offline); that is
  what the API check above did once by hand, and what Dependabot rewrites.
- `.github/dependabot.yml` (D): valid `version: 2`, `package-ecosystem: github-actions`, `directory: /`, weekly. Its comment
  is accurate: directory `/` makes Dependabot read `.github/workflows` (and root action.yml) and not
  `templates/github-actions`. It also does not scan `.github/actions/*` composites (the one present has no remote `uses:`).
- Documented routes (D): `src/` does not parse `uses:` tags; only `WorkflowBaseGateDetector` string-matches `shivammathur/setup-php`
  (unaffected). Consumers copy the templates; the CHANGELOG states they stay on old tags until re-copied.

## 5. Mergeability

MERGEABLE, no conflict; BLOCKED only by pending CI. Throwaway worktree `untracked/worktrees/verify-441` removed.

## Notes (non-blocking)

A. marocchino `# v3` is a branch name; consider commenting `# v3.0.5` (the release the SHA is) so the comment is a release.
B. Over-flagging of quoted/`docker://` forms and the `{uses: ...}` flow-mapping blind spot; document or tighten if such forms ever appear.
C. `docs/github-actions.md` (lines 410, 420) still shows tag-form examples (`codecov/codecov-action@v3`, `8398a7/action-slack@v3`); not part of this PR's claim.
D. As the PR body says: no Dependabot cooldown, and `dependabot/*` branches fail the branch-name policy until allow-listed (Owner decisions, unchanged here).
