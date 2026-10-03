# Releases and the changelog

php-qa-ci releases itself. Nobody picks a version number and nobody tags by hand: the
changelog decides the version, CI keeps a release pull request open for it, and merging that pull
request is the release. This file is the single source of truth for how that works and what a
change has to carry for it to work.
The rules of the changelog format and the lane that enforces them are in
[docs/tools/changelog.md](../docs/tools/changelog.md); the version scheme consumers see is under
[Branches and versions](../README.md#branches-and-versions).

## Every change to a shipped path carries a changelog entry

`qaConfig/qa.php` switches the `changelog` lane on (`phpqaci.changelog`) and lists the watched
paths: everything a consumer receives or has deployed into their project (`src/`, `bin/`,
`configDefaults/`, `templates/`, `scripts/`, `git-hooks/`, the PHARs and binaries, `build/`,
`phive.xml`, `composer.json`, `rules-*.neon`, and the deployed `.claude/` agents, hooks and
skills). `tests/`, `docs/`, `CLAUDE/`, `qaConfig/`, `.github/` and `CHANGELOG.md` itself are not
watched.

When a branch changes a watched path, the same branch adds an entry under `## Unreleased` in
`CHANGELOG.md`:

```bash
bin/changelog-release add-entry fixed '**The lock is released on SIGTERM.** <what a consumer sees>'
```

Pick the heading by what a consuming project experiences, because the heading decides the
release:

- `Changed — breaking`: they must act (a new requirement, a lane that now fails what it passed,
  a changed exit code or config contract). Minor release, flagged BREAKING in the tag.
- `Removed`: something they could rely on is gone. Minor, flagged BREAKING.
- `Added`, `Changed`, `Deprecated`: new or different behaviour they need not act on. Minor.
- `Fixed`, `Security`: a defect corrected. Patch, when nothing else is in the section.

Write entries the way the existing ones are written: a bold one-sentence statement of the
change, then what a consumer sees and does, never how the code was restructured. A
`composer.json` requirement that is added or tightened must be under `Changed — breaking`; the
lane checks that one mechanically.

### When there is nothing to record

A change no consuming project could notice (an internal refactor, a comment, a rename inside a
`@internal` class) gives one commit in the branch the trailer instead of an entry:

```bash
git commit --trailer 'Changelog: none — internal refactor, no behaviour change'
```

The reason is mandatory and must say why in at least two words: a bare `Changelog: none`, or
`none — wip`, is refused. Do not use the trailer to skip an entry that is merely tedious: if a
consumer's build, output or install can differ, it needs an entry.

## How a release happens

This is the release pull request pattern of release-please and changesets. The difference is that
the changelog is written by hand, and enforced by the lane, rather than derived from commit
messages. The workflow is `.github/workflows/release.yml`; every decision in it is a
`bin/changelog-release` subcommand.

1. A branch lands on `php8.5` (in this estate: merged locally by the lead, pushed by the owner,
   per the consuming project's `lts/*` workflow).
2. CI (`.github/workflows/ci.yml`) runs the full pipeline. On `php8.5` the changelog lane judges
   everything since the latest `85.N.N` tag, so a merge that slipped through without an entry
   fails here.
3. When CI is green, `release.yml` runs (`workflow_run`). `next-version` gives the version
   `## Unreleased` releases as; empty output means there are no entries and nothing to do. Otherwise
   it runs `apply <version> <today>` and opens, or force-refreshes, the pull request
   `Release <version>` from `chore/release-php8.5`, whose only change is `CHANGELOG.md` and whose
   body is the release notes. Every later green push refreshes it, so it always releases
   everything recorded so far.
4. **Merging the release pull request is the decision to release.** Merge it like any other
   (`gh pr merge <n> --merge`); nothing else is required, and nothing is released until then.
5. CI runs on the merge. The lane counts the new version section as the record of everything
   since the last tag, so it passes. `release.yml` then runs `pending-tags`, which names each
   version section newer than the newest tag together with the commit that wrote it, and runs
   `gh release create <version> --target <commit>` with `notes <version>` as the notes. That one
   call creates the tag and the GitHub Release, so a re-run never finds one without the other.
   Packagist reads the tag.

The workflow acts only on the commit CI verified. When `php8.5` has moved on, it stops with a
notice and the run for the newer push does the work. Runs are serialised by the `release-php8.5`
concurrency group and never cancelled. The tag points at the commit that wrote the version
section, not at the merge, so a release contains exactly what its notes describe, even when
`php8.5` gained commits between the pull request's last refresh and its merge (their entries
stay under `## Unreleased` for the next release).

If `## Unreleased` is emptied by hand while a release pull request is open, the next green push
closes the pull request.

The version is the PHP line from `composer.json` (`^8.5` is `85`), then a minor or patch bump
over the newest `85.N.N` tag. Bumping the PHP requirement on this branch would therefore move to a
new line; that is a new branch (`php8.6`), not a release of this one.

## Repository settings it relies on

The workflow needs no secret and bypasses no branch protection: `GITHUB_TOKEN` opens the pull
request and creates the release, and the owner's merge is the only write to `php8.5`. It relies
on these settings:

- **Settings → Actions → General → Workflow permissions**: "Allow GitHub Actions to create and
  approve pull requests" is on (`gh api repos/LongTermSupport/php-qa-ci/actions/permissions/workflow`
  shows `can_approve_pull_request_reviews: true`).
- No ruleset targets tags, so `GITHUB_TOKEN` may create `85.N.N`.
- `php8.5`'s classic branch protection requires the status checks `QA Pipeline` and
  `ShellCheck (severity=warning)`, and is not enforced on admins
  (`gh api repos/LongTermSupport/php-qa-ci/branches/php8.5/protection`). A pull request opened or
  pushed with `GITHUB_TOKEN` starts no workflow, so `release.yml` dispatches CI
  (`gh workflow run ci.yml --ref chore/release-php8.5`) whenever it opens or updates the release
  pull request; that run reports `QA Pipeline` on its head commit.
- `ShellCheck (severity=warning)` is reported by nothing: ShellCheck runs inside `bin/qa` as the
  `shellCheck` lane, and the job that once reported that check is gone. Until the context is
  removed from the branch protection (Settings → Branches → `php8.5` → required status checks),
  no pull request can be merged except by an admin, which includes the release pull request
  (`gh pr merge <n> --merge --admin`).
