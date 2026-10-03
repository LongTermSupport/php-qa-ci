# Plan 00014: changelog release automation

**Status**: In Progress
**Created**: 2026-10-02
**Owner**: Joseph Edmonds
**Priority**: Medium

## Overview

Releases on the `php8.5` line are cut by hand today, and nothing ties a change to a changelog
entry, so a consumer cannot tell from a tag what moved or whether it can break them. This plan
makes `CHANGELOG.md` the single input for releasing: every change to a path a consumer receives
must be recorded under `## Unreleased` (or explicitly declared invisible), a green push to
`php8.5` keeps a release pull request open for the next version (derived from the headings used),
and merging it publishes the GitHub Release and its tag.

The building blocks are written and covered by tests on branch
`feature/changelog-release-automation`. What is left is the full-pipeline proof, the owner
decisions below, the merge, the one-time repository setup and watching the first real release.

## Owner decisions (fixed)

- **The major version is the PHP line** (`85.x.y` for `php8.5`, read from `composer.json`
  `require.php`). It never moves for a code change, so a release is only ever minor or patch.
- **Bump rules** from the `## Unreleased` headings: `Changed — breaking`, `Removed`, `Added`,
  `Changed`, `Deprecated` give a minor bump; `Fixed` / `Security` alone give a patch; an empty
  section releases nothing; an unknown, duplicated or empty heading, or text outside a heading,
  is invalid and fails rather than guessing. Tag notes flag BREAKING when `Changed — breaking`
  or `Removed` is present.
- **Release pull request, not bot commits** (revised 2026-10-02 at the owner's request to follow
  standard practice): `.github/workflows/release.yml`, separate from CI and run on its green
  completion, keeps a `Release <version>` pull request open from `chore/release-php8.5`; merging
  it is the release, and CI on the merge publishes the GitHub Release with
  `gh release create --target <the commit that wrote the section>`. `GITHUB_TOKEN` does all of
  it: no deploy key, no secret, no ruleset bypass, no bot commits on `php8.5`. This supersedes
  the earlier deploy-key design.
- **Enforcement**: a changed watched path needs a new Unreleased entry or a
  `Changelog: none — <reason>` commit trailer; a new or tightened `composer.json` requirement
  needs a `Changed — breaking` entry.

## Goals

- `bin/changelog-release` (`next-version`, `apply`, `notes`, `add-entry`) drives every release.
- The opt-in `changelog` lane (`phpqaci.changelog`) is enabled on php-qa-ci itself and fails a
  branch whose consumer-facing changes are not recorded.
- A green push to `php8.5` opens or refreshes the release pull request; merging it publishes the
  release and its tag with no further step.
- The weekly dependency update records its own changelog entry, so it passes the lane.

## Non-Goals

- Changing the version scheme or releasing other branches (`php8.4`, `php8.3`).
- Detecting every class of breaking change (removed lanes or aliases, exit-code changes, public
  PHP API breaks via a backward-compatibility checker) — candidates for a follow-up plan.
- Turning the lane on for consuming projects; it stays opt-in.

## Tasks

### Phase 1: Build (branch `feature/changelog-release-automation`)

- [x] **Task 1.1**: Changelog model under `src/Changelog/`: parser, version line and bump,
  release rewrite, notes renderer, entry adder, range resolver, trailer reader, requirement
  detector (`924ff84`)
- [x] **Task 1.2**: `changelog` lane in the linting phase after `versionPins`, opt-in via
  `withChangelogCheck()` / `withChangelogWatchedPaths()` / `useChangelogCheck`, enabled in
  `qaConfig/qa.php`; `bin/changelog-release` entry point (`0efcfe3`)
- [x] **Task 1.3**: Large tests against real git history in a sandbox repository (`9c05551`)
- [x] **Task 1.4**: `ci.yml` full-history checkout and `release` job; `update-deps.yml`
  changelog entry before its QA run (`e1c02b7`)
- [x] **Task 1.5**: README version scheme, `CHANGELOG.md` header and a valid Unreleased section,
  `CLAUDE/releases.md` including the one-time owner setup, lane docs and indexes (`61a637e`)
- [x] **Task 1.6**: CS and dead-code follow-ups (`841fc4f`, `84adb38`)

### Phase 2: Prove and decide

- [x] **Task 2.0**: Release redesign to the release pull request pattern: `ReleasedSections`,
  `pending-tags`, the lane counting a released-but-untagged section as the record,
  `release.yml`, `ci.yml` without the release job, docs (`c9252e6`, `fb9aa95`, `a88c069`)

- [x] **Task 2.1**: Infection in diff mode against `origin/php8.5`: 91% covered MSI, passing.
  Owner ruling: never a 100% floor. The diff-mode default of 100 (from the Bash port) is gone,
  diff mode follows the covered floor, and `build()` refuses any MSI floor of 100 or more
  (`a338cdc`). The flaky `RunningProcessesTest` was a real race, fixed in `stopAll()` (`84d2235`)

- [x] **Task 2.2**: Full unfiltered `CI=true bin/qa` exit 0 (1466 tests, full-mode Infection
  85% covered MSI), then `QA_READONLY=1 CI=true bin/qa` on the committed tree: every tool passed
  Tasks 2.3 to 2.6 were settled on the owner's instruction to apply common sense:

- [x] **Task 2.3**: The five entries the `85.0.0` tag shipped moved, verbatim, into
  `## 85.0.0 — 2026-10-02` (`a88c069`)

- [x] **Task 2.4**: The consumer template's `qa` job always checks out with `fetch-depth: 0`
  (`fb9aa95`)

- [x] **Task 2.5**: `changelog-release add-tool-updates` writes the moved versions into the
  entry, read from the pins rather than `--version` banners (`c9252e6`, `fb9aa95`)

- [x] **Task 2.6**: The two `QaConfigDto` parameters default to off, so the change is genuinely
  additive and `Added` is right (`c9252e6`)

### Phase 3: Land and switch on

- [x] **Task 3.1**: Merge `feature/changelog-release-automation` into `php8.5` with `--no-ff`
  (`ca6b529`)
- [x] **Task 3.2**: Repository settings: Actions may open pull requests and no ruleset targets
  tags. Classic branch protection on `php8.5` requires "QA Pipeline" and the stale
  "ShellCheck (severity=warning)", which nothing reports. A pull request opened with
  `GITHUB_TOKEN` starts no workflows, so `release.yml` dispatches CI on the release branch
  (`4b97873`, merged `adae4f3`). Recorded in `CLAUDE/releases.md`. Removing the stale check is
  the owner's setting
- [ ] **Task 3.3**: Push `php8.5`; watch the first run: CI green, `release.yml` opens
  `Release 85.1.0`. Merge it; CI on the merge is green and `release.yml` publishes the
  `85.1.0` GitHub Release at the release commit
- [ ] **Task 3.4**: Confirm Packagist lists `85.1.0`, and that a fresh install with
  `^85.1` / `~85.1.0` resolves it
- [ ] **Task 3.5**: Diagnose the weekly `Update Dependencies` workflow, which failed on its last
  two scheduled runs before this work, and confirm its first run after the merge passes the lane.
  Diagnosed: both failed at QA, read-only by default in Actions, on pending changes from a newer
  Rector; the auto-merge step after it could never succeed (`allow_auto_merge: false`). Fixed:
  the QA run is writable (`QA_READONLY: 0`) so new rules land in the PR, and the auto-merge step
  is gone; the owner merges the PR. Still to confirm on the first scheduled run

## Success Criteria

- [x] The full pipeline exits 0 on the branch, and the read-only gate passes on the committed tree
- [ ] A branch changing a watched path with no entry and no reasoned trailer fails the
  `changelog` lane in a real run
- [ ] The first green push after the merge opens the release pull request, and merging it yields
  the GitHub Release and tag at the release commit without any further step
- [ ] A push that only touches unwatched paths and adds no entry releases nothing

## Delivery & Milestones

- Build commits: `924ff84`, `0efcfe3`, `9c05551`, `e1c02b7`, `61a637e`, `841fc4f`, `84adb38`
- Release pull request redesign and decisions: `c9252e6`, `fb9aa95`, `a88c069`
