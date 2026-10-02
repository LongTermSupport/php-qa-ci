# Plan 00014: changelog release automation

**Status**: In Progress
**Created**: 2026-10-02
**Owner**: Joseph Edmonds
**Priority**: Medium

## Overview

Releases on the `php8.5` line are cut by hand today, and nothing ties a change to a changelog
entry, so a consumer cannot tell from a tag what moved or whether it can break them. This plan
makes `CHANGELOG.md` the single input for releasing: every change to a path a consumer receives
must be recorded under `## Unreleased` (or explicitly declared invisible), and CI turns a green
push to `php8.5` into a release commit plus an annotated tag, with the version derived from the
headings used.

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
- **Bot commits**: the CI release job commits the changelog rewrite to `php8.5` itself
  (`Release <version> [skip ci]`), tags it and pushes both atomically, using a write deploy key
  that bypasses the PR rule.
- **Enforcement**: a changed watched path needs a new Unreleased entry or a
  `Changelog: none — <reason>` commit trailer; a new or tightened `composer.json` requirement
  needs a `Changed — breaking` entry.

## Goals

- `bin/changelog-release` (`next-version`, `apply`, `notes`, `add-entry`) drives every release.
- The opt-in `changelog` lane (`phpqaci.changelog`) is enabled on php-qa-ci itself and fails a
  branch whose consumer-facing changes are not recorded.
- A green push to `php8.5` produces a release commit and an annotated tag with no human step.
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

- [ ] **Task 2.1**: Infection in diff mode against `origin/php8.5`
  (`infectionDiffBase=origin/php8.5 bin/qa -t infection`) — the run was interrupted before it
  reported
- [ ] **Task 2.2**: Full unfiltered `CI=true bin/qa` in the worktree, exit 0, then the read-only
  gate from `CLAUDE/prepush-verification.md`
- [ ] **Task 2.3**: Owner decision — five Unreleased entries already shipped in `85.0.0` (lock
  contention exit 75, `rule-doc` project indexes, per-file PHPStan on phar configs, log
  retention, the managed-block blank line). Move them into a `## 85.0.0 — 2026-10-02` section
  before the first automated release, or let `85.1.0` repeat them
- [ ] **Task 2.4**: Owner decision — should the consumer workflow template
  (`templates/github-actions/php-qa-ci.yml`, kept identical to `.github/workflows/qa.yml`)
  default to `fetch-depth: 0` so a consumer can enable the lane without editing it
- [ ] **Task 2.5**: Owner decision — `update-deps` records the changed PHAR paths, not their
  versions; add a subcommand that writes versions into the entry, or leave versions in the PR
  body
- [ ] **Task 2.6**: Owner decision — `QaConfigDto` (`@api`) gained two required constructor
  parameters; recorded as `Added` because only the builder constructs it. Confirm, or reclassify
  as `Changed — breaking`

### Phase 3: Land and switch on

- [ ] **Task 3.1**: Merge `feature/changelog-release-automation` into `php8.5` with `--no-ff`
- [ ] **Task 3.2**: One-time repository setup, per `CLAUDE/releases.md`: deploy key with write
  access, Actions secret `RELEASE_DEPLOY_KEY`, "Deploy keys" added to the `protect` ruleset's
  bypass list. Until this is done every push shows a red `release` job; `qa` is unaffected
- [ ] **Task 3.3**: Push `php8.5`; watch the first run: `qa` green, `release` commits
  `Release 85.1.0 [skip ci]`, tags `85.1.0` with the notes, and the release commit triggers no
  CI run of its own
- [ ] **Task 3.4**: Confirm Packagist lists `85.1.0`, and that a fresh install with
  `^85.1` / `~85.1.0` resolves it
- [ ] **Task 3.5**: Diagnose the weekly `Update Dependencies` workflow, which failed on its last
  two scheduled runs before this work, and confirm its first run after the merge passes the lane

## Success Criteria

- [ ] The full pipeline exits 0 on the branch, and the read-only gate passes on the committed tree
- [ ] A branch changing a watched path with no entry and no reasoned trailer fails the
  `changelog` lane in a real run
- [ ] The first green push after the merge yields a release commit and a matching annotated tag
  without any manual step
- [ ] A push that only touches unwatched paths and adds no entry releases nothing

## Delivery & Milestones

- Build commits: `924ff84`, `0efcfe3`, `9c05551`, `e1c02b7`, `61a637e`, `841fc4f`, `84adb38`
