# Plan 00020: tool currency and phpstan turbo

**Status**: In Progress
**Created**: 2026-10-08
**Owner**: dev
**Priority**: High

## Overview

The Owner's ruling: tracking current tools is a standing process, not an occasional chore. php-qa-ci
should always run on the latest version of every tool it bundles that passes QA against this
project, and consumers get those versions through it. PHPStan Turbo is to be used, here and in
every client project.

Most of the machinery exists. `.github/workflows/update-deps.yml` runs `composer update`, the PHIVE
update and the self-built PHAR rebuilds weekly, runs QA and opens `chore/update-deps`. But it does
not land. The last pull request it opened, #52, has been red since it opened: the rebuilt self-built
PHARs differ byte for byte, `add-tool-updates` records nothing because no pinned version moved, and
the changelog lane fails. The job's own QA run passed, because it judged the default branch, not
the pull request. Nothing and nobody picks the pull request up. As a result `phpstan.phar` is
2.2.16 while 2.2.17 and 2.3.0 are out.

Turbo is PHPStan's native extension, loaded into its worker processes. Since PHPStan 2.2.6 the phar
looks for it in a `turbo-ext/` directory beside itself, so shipping that directory in `vendor-phar/`
gives every consumer Turbo with no action on their part. #18 researched this and deferred it.
The Owner has now decided to adopt it. The research's conditions remain requirements: the binaries
come from the same tag as the phar, and a parity check holds them together. A mismatch silently
disables Turbo. Turbo also uses more memory.

## Goals

- The dependency update runs often, judges itself as the pull request's CI will, and lands when green
- When an update fails QA, the failure is reported and fixed (Defence Before Fix); it is never left
  for nobody to see
- `phpstan.phar` at the latest release (2.3.0 at the time of writing), with this project green on it
- Turbo shipped in `vendor-phar/turbo-ext/`, from the same tag as the phar, and proven enabled in
  the `phpstan` lane, here and in a consumer-shaped install
- The standing process is written down in `CLAUDE/` and is part of every session's routine

## Non-Goals

- `php8.4`: it takes reported fixes only (#18 records its PHPStan pin)
- Installing Turbo through PIE or any per-host step: the sibling-directory route needs none
- Porting or patching PHPStan or Turbo

## Tasks

### Phase 1: the update lands (Defence Before Fix: detector red first)

- [x] ✅ **Task 1.1**: Find why a no-version-change rebuild of the self-built PHARs differs byte for
  byte. Cause: Box's random alias, plus Composer recording this repository's branch and commit. Red
  `c65d197`, fix `2160764`: a fixed alias per tool and a fixed `COMPOSER_ROOT_VERSION`.
- [x] ✅ **Task 1.2**: `add-tool-updates` records every package of a build lock, so a dependency that
  moved inside a self-built PHAR is recorded. Red `6857aba`, fix `d941c4e`.
- [x] ✅ **Task 1.3**: The update job runs QA on a work branch, judged from the merge base as its pull
  request will be. Red `3daaa2c`, fix `7369f06`.
- [x] ✅ **Task 1.4**: The job runs daily, and a failed run comments on the open
  `update-deps-failure` issue or opens one. Red `0f3a68f`, fix `4f2d59c`.
- [x] ✅ **Task 1.5**: Decide who merges a green update pull request (D1, decided: an agent session,
  after a fresh sub-agent's verification). Documented in `CLAUDE/tool-currency.md`, with a binding
  section in `CLAUDE.md` that makes the check part of every session.

### Phase 2: PHPStan 2.3.0

- [x] ✅ **Task 2.1**: `phpstan.phar` 2.2.16 → 2.3.0 through `scripts/tool-install.bash update`, as
  its own commit (`6a53669`). It reported 162 findings.
- [x] ✅ **Task 2.2**: 158 came from one class: the rule tests' scope double lacked
  `DependencyTracker`, which 2.3.0 adds to `Rule::processNode()`'s scope type. Red `a32227e` (read
  from the phar's own PHPDoc), fix `2741e00`. Seven identifiers with no upstream page got pages under
  `docs/phpstan-extension-rules/`, and the last four findings were fixed in `cd85df1`. No baseline.
- [ ] 🔄 **Task 2.3**: Every custom rule still registers (the rule-listing and agent-summary tests
  pass). Supersede #52 once this lands.

### Phase 3: PHPStan Turbo

- [ ] ⬜ **Task 3.1**: Red: a Large test that runs `vendor-phar/phpstan.phar diagnose` the way the
  lane invokes PHP (`PhpInvoker`, no Xdebug, the memory limit) and requires `Turbo extension: enabled`.
- [ ] ⬜ **Task 3.2**: Fetch `turbo-ext/` from the phar's own tag in the PHAR update path (PHP, not
  Bash, per the ShellCheck installer pattern), for PHP 8.5 non-ZTS on the platforms upstream ships.
  Record the size cost.
- [ ] ⬜ **Task 3.3**: A parity defence: the shipped `turbo-ext` version must be the one the shipped
  phar expects. A test, plus a `PharToolsVerifier` check, so a mismatch fails rather than silently
  running without Turbo.
- [ ] ⬜ **Task 3.4**: The `phpstan` lane reports whether Turbo loaded. On a platform with a shipped
  binary where it does not load, the cause is shown (decide warn or fail; see Decisions).
- [ ] 🔄 **Task 3.5**: Measure on this repository: wall time and peak memory, Turbo on against off,
  interleaved. Measured on 2.3.0: about 20.4 s → 9.4 s, and worker peak memory up about 6% (see the
  journal). Still to do: document it, and the memory-limit guidance, in `docs/tools/phpstan.md`.
- [ ] ⬜ **Task 3.6**: A consumer-shaped Large test, with php-qa-ci under
  `vendor/lts/php-qa-ci`, proving the lane runs with Turbo enabled.

### Phase 4: release and roll-out

- [ ] ⬜ **Task 4.1**: Changelog entries (PHPStan 2.3.0, with new findings expected; Turbo on by
  default, with its memory note), then the release pull request through the normal gate.
- [ ] ⬜ **Task 4.2**: Confirm Turbo enabled in at least one real client project's `phpstan` lane
  after it updates, and record the evidence in the journal.

## Decisions

- **D1 (Owner, decided): who merges a green dependency-update pull request.** An agent session
  merges it after a fresh sub-agent's verification (`pr-verification.md`), as a standing
  authorisation. No auto-merge.
- **D3 (Owner, open): how Turbo reaches consumers.** Upstream does bundle Turbo, but only in the
  Composer package: `phpstan/phpstan` at a tag carries `turbo-ext/` (every platform) beside
  `phpstan.phar`, and `.gitattributes` does not export-ignore it. The GitHub release that PHIVE
  downloads has `phpstan.phar` and its signature only. php-qa-ci takes the phar from PHIVE and
  `replace`s `phpstan/phpstan` (README: to prevent version conflicts when consumers also require
  PHPStan extensions),
  so neither php-qa-ci nor a consumer ever receives `turbo-ext/`. At 2.3.0 each PHP 8.5 binary is
  about 7 MB, and its version changes with most PHPStan releases. (a) Commit every non-Windows
  platform under `vendor-phar/turbo-ext/`: about 35 MB of git history per PHPStan bump. (b) Commit
  linux-gnu-x86_64 only: about 7 MB per bump; other hosts run without Turbo and the lane says so.
  (c) Fetch the host's own binary from the phar's tag during `composer install`/`update`, pinned by
  a committed SHA-256 manifest: nothing in git, Turbo everywhere, but a network fetch at install.
  (d) Drop the `replace` and require `phpstan/phpstan` at the exact version of the shipped phar, so
  Composer delivers `turbo-ext/` as upstream intends; the lane then runs Composer's
  `phpstan.phar` and PHIVE stops handling PHPStan. Nothing in git and no fetching code of our own,
  but about 45 MB in every consumer's `vendor/`, and an exact pin a consumer's own PHPStan
  constraint must accept.
- **D2: when Turbo should load but does not.** The lane prints the cause either way. Recommendation:
  fail, since a silent loss of Turbo is the failure #18 warned of. A host without a shipped binary
  (another platform or a ZTS build) is reported, not failed.

## Success Criteria

- [ ] The update job's QA verdict and its pull request's CI verdict agree, by construction
- [ ] A green update pull request lands without anyone remembering to look
- [ ] `phpstan.phar` is the latest release and the full battery is green on it
- [ ] The Turbo Large tests pass, and the lane reports Turbo enabled here and in a client project
- [ ] `CLAUDE/tool-currency.md` states the standing process

## Delivery & Milestones

- <!-- milestone or delivery commit hash -->
