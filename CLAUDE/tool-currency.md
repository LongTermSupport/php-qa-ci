# Tool currency: the standing process

php-qa-ci runs on the latest release of every tool it bundles that passes QA against this
repository, and its consumers get those versions through it. This is the Owner's standing ruling.
It is a process that runs every day. It is not a chore done when someone remembers it.

## What runs on its own

[`.github/workflows/update-deps.yml`](../.github/workflows/update-deps.yml) runs daily, and on demand
through `workflow_dispatch`. It does the following:

1. It runs `composer update`.
2. It runs the PHIVE update (`scripts/tool-install.bash update`).
3. It bumps every `build/<tool>/` manifest and rebuilds the self-built PHARs. These builds are
   reproducible: a rebuild with no version change is byte for byte the same, so it is not a change.
4. It regenerates the active-defences region of `CLAUDE.md`.
5. It records each moved tool, and each package that moved inside a self-built PHAR, under
   `## Unreleased` (`bin/changelog-release add-tool-updates`).
6. It runs the full pipeline, writable, on a work branch. That makes the changelog lane measure from
   the merge base, as it will on the pull request.
7. When QA passes, it opens or updates the `chore/update-deps` pull request.

When the run fails, it comments on the open issue labelled `update-deps-failure`. When no such
issue is open, it opens one. A failure is never silent.

## What an agent session does

At the start of every session on this repository, and again before the session ends, run these
three checks:

```bash
gh pr list --state open --label dependencies --json number,title,headRefOid,statusCheckRollup
gh issue list --state open --label update-deps-failure --json number,title,comments
gh issue list --state open --json number,title,author,comments,updatedAt
```

Read every open issue that has had no reply. A consumer's report, such as a tool version clash after
an update, is often this process failing for someone, and it is acted on in the same session.

**A green update pull request: verify it, then merge it.**

- An agent session merges it, and nobody needs to ask the Owner first. This is a standing
  authorisation from the Owner.
- The merge waits on a **fresh sub-agent's verdict**, exactly as
  [pr-verification.md](pr-verification.md) describes.
- The workflow does not merge on its own, and nothing enables auto-merge. A new tool version can
  change what the tools report, and a reader who did not write the change looks at it first.
- When the verdict is PASS, comment the verdict and merge with
  `gh pr merge --merge --match-head-commit <sha>`.

**An update pull request that is red, or an open `update-deps-failure` issue: fix the cause.**

1. Read the failing step.
2. Follow Defence Before Fix ([DefenceBeforeFix.md](DefenceBeforeFix.md)). A new tool version that
   reports findings in this repository is a defect here, not a reason to hold the tool back. Fix the
   findings. A defect class the update exposed gets its detector first.
3. Do the work on your own branch off `php8.5`, not on `chore/update-deps`. The workflow resets that
   branch on every run.
4. Merge your fix through the normal gate, then run the workflow again (`gh workflow run update-deps.yml`).
   Close the issue when an update lands green.

A tool is held back only by the Owner's decision. Pinning an older version is a narrowing, so it is
recorded and never made as a silent edit.

## Doing it by hand

The workflow's update steps also run locally, which is how a maintainer takes a release the same
day:

```bash
composer update
bash scripts/tool-install.bash update
for manifest in build/*/composer.json; do composer update --working-dir="$(dirname "$manifest")" --no-dev; done
bash scripts/build-phar.bash --all --force
php bin/changelog-release add-tool-updates
```

Then run the battery ([prepush-verification.md](prepush-verification.md)). The change goes through
a pull request like any other.
