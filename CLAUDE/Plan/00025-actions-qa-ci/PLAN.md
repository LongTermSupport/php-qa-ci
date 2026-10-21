# Plan 00025: actions-qa-ci

**Status**: In Progress
**Created**: 2026-10-21
**Owner**: dev
**Priority**: Medium

## Overview

GitHub Actions workflow code has no defence in this repository or in what it ships to consumers.
An audit ([research/ci-audit.md](research/ci-audit.md)) found three problems:

- **The QA pipeline runs twice on every PR push.** It runs from `ci.yml` (`pull_request`) and from
  `qa.yml` (a byte-identical dogfood copy of the shipped template `templates/github-actions/php-qa-ci.yml`,
  on `push` to `bugfix/**` and the other branch prefixes). The template double-runs in every consumer
  with a `main` PR flow too.
- **The workflows lack basic hardening:** no `concurrency`, no `permissions:` blocks, actions pinned by
  mutable tag, a workflow-wide write token in `update-deps.yml`, and template injection in two places.
- **Two existing linters confirm defects that no lane catches:** actionlint (14 findings, one a real
  schema error) and zizmor (40 findings in `.github/`, 36 in `templates/`).

The Owner's direction is a standalone, polyglot, Defence-Before-Fix-compatible QA/CI toolchain for
Actions code, **actions-qa-ci**, usable in any repository, with php-qa-ci as its first dogfood
consumer. The survey ([research/actions-tool-survey.md](research/actions-tool-survey.md)) finds
zizmor the best fit for DBF identifiers: about 40 audits, stable ids, a doc anchor per audit, and
SARIF. actionlint covers schema and expressions. pinact fixes unpinned `uses:`.

This plan does three things:

- fixes php-qa-ci's own CI duplication;
- closes the found defects under DBF;
- designs and bootstraps actions-qa-ci.

## Goals

- One full QA run per PR push, here and in the shipped consumer template, with a stable required
  check name.
- Every workflow here and in `templates/github-actions/` passes a pinned actionlint and zizmor, or
  carries a justified suppression.
- **actions-qa-ci exists as a standalone library with:**
  - pinned tools;
  - a stable identifier and doc page per check;
  - a `rules` listing;
  - a suppression-justification lane;
  - one entry point;
  - SARIF output.
- php-qa-ci consumes actions-qa-ci, so its own workflows are checked on every run.

## Non-Goals

- Runtime egress monitoring (harden-runner) or org-level posture scoring (Scorecard, Legitify) as a
  gate; Scorecard may become a scheduled report later.
- Re-implementing what actionlint or zizmor already check.
- Changing the release mechanism (`release.yml` stays the shipped template).

## Tasks

### Phase 1: Owner decisions

- [x] ✅ **Task 1.1**: CI shape: **Option A** (Owner decision), from
  [research/ci-audit.md § 1.7](research/ci-audit.md#17-options). The template runs on
  `pull_request` and on a push to the default branch, and `ci.yml` retires. The Owner's choice
  authorises rewriting the defence test
  `GitHubActionsTemplatesTest::thePipelineTemplateRunsOnEveryBranchPrefixThePolicyAllows` to assert
  the new trigger set.
- [x] ✅ **Task 1.2**: Language and distribution (Owner decision): **full polyglot**. actions-qa-ci
  is not PHP-specific. It serves any repository or project that has Actions code. The toolchain may
  be in any language. It is optimised to install easily in any Linux environment, so it does not
  require PHP or any other language runtime on the host. The design document (Task 4.1) chooses the
  toolchain against that constraint.
- [x] ✅ **Task 1.3**: Create the public repository now (Owner decision):
  `LongTermSupport/actions-qa-ci`, MIT like php-qa-ci.

### Phase 2: Defects found by the audit (DBF, issue loop)

- [ ] ⬜ **Task 2.1**: `scripts/setup-branch-protection.bash` requires a `ShellCheck (severity=warning)`
  context that no workflow produces, which would block every merge. The fix needs a detector first,
  e.g. a test that every required context is a job name a workflow produces.
- [ ] ⬜ **Task 2.2**: The template defects actionlint and zizmor report, fixed once the lane from
  Phase 4 is red on them:
  - the empty `choice` option;
  - the `github.head_ref` and input template injection;
  - excessive permissions;
  - `artipacked`;
  - unpinned `uses:`.

### Phase 3: CI made pragmatic (after Task 1.1)

- [ ] ⬜ **Task 3.1**: The template runs once per PR push and on a push to the default branch, with:
  - `concurrency` with cancel-in-progress;
  - least-privilege `permissions:`;
  - the PHP version as a step;
  - a static job name;
  - Coverage Report opt-in.
- [ ] ⬜ **Task 3.2**: Retire `ci.yml`. Point `update-deps.yml` at `qa.yml`. Move `ci.bash`'s settings into
  `qaConfig/qa.php` or the workflow `env:`. Add caching.
- [ ] ⬜ **Task 3.3**: Split `update-deps.yml` into a read-only build and QA job and a write job that only
  opens the PR.
- [ ] ⬜ **Task 3.4**: Docs, branch-protection script, `.claude/rules` path list, and a CHANGELOG entry
  (a consumer-visible check-name change).

### Phase 4: actions-qa-ci (after Tasks 1.2 and 1.3)

- [ ] ⬜ **Task 4.1**: Design document:
  - identifier scheme (`actionsqaci.<tool>.<upstream-id>`) and one owner per hazard class;
  - pinned-tool manifest;
  - justification lane;
  - entry point;
  - SARIF merge;
  - the toolchain, chosen for a runtime-free install on any Linux host (Task 1.2);
  - which DBF conventions are taken from php-qa-ci as conventions, not as code.
- [ ] ⬜ **Task 4.2**: Bootstrap the repository with pinned actionlint, zizmor and pinact, `rules`,
  `rule-doc` and the justification lane.
- [ ] ⬜ **Task 4.3**: First-party lanes:
  - template drift (generalising the versionPins identity check);
  - workflow coverage outside `.github/`;
  - required checks matching produced job names (from Task 2.1).
- [ ] ⬜ **Task 4.4**: php-qa-ci consumes actions-qa-ci as a lane; every finding is fixed or justified.

## Success Criteria

- [ ] A PR push to php-qa-ci starts exactly one full QA run.
- [ ] A consumer installing the template gets one run per PR push and a required check whose name
  does not change with the PHP line.
- [ ] `bin/qa` fails on a new unpinned `uses:`, a template injection or an excessive permission in
  any workflow here or in `templates/github-actions/`.
- [ ] actions-qa-ci runs green on its own repository and on php-qa-ci.

## Delivery & Milestones

- Audit and tool survey: [research/ci-audit.md](research/ci-audit.md),
  [research/actions-tool-survey.md](research/actions-tool-survey.md).
