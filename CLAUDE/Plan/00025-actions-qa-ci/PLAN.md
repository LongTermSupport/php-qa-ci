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
- [ ] ⬜ **Task 1.4**: Conformance reading ([design.md § 3](design.md#3-conformance-framing-toolchain-41)):
  actions-qa-ci is the Detector, and zizmor and actionlint are bundled rule backends.
- [ ] ⬜ **Task 1.5**: zizmor provenance in v1 ([design.md § 5.2](design.md#52-zizmor-provenance)): the
  sha256-pinned PyPI musl wheel, or a self-built and attested binary.
- [ ] ⬜ **Task 1.6**: Default strictness: zizmor `regular` persona and shellcheck `warning`, which is
  narrower than upstream's full catalogue.
- [ ] ⬜ **Task 1.7**: Whether the standing authorisation to merge green tool-update PRs after a
  fresh sub-agent's verification extends to the new repository.
- [ ] ⬜ **Task 1.8**: Confirm or change the defaults the design proposes
  ([design.md § 14](design.md#14-owner-decisions-needed) items 3, 6, 7 and 8). Each is the design's
  recommendation and is applied unless the Owner changes it:
  - Linux amd64 and arm64 only, with no darwin or armv7 (item 6, following the Owner's "Linux");
  - default branch `main` with one release line (item 7);
  - no exec-rule kind (item 8);
  - a 3-day wait before adopting a new tool release (item 3), a deliberate difference from
    php-qa-ci's same-day tool-currency process.
  - Item 9, the `dangerous-triggers` exception, is decided in Task 2.2.

### Phase 2: Defects found by the audit (DBF, issue loop)

- [x] ✅ **Task 2.1**: `scripts/setup-branch-protection.bash` required a `ShellCheck (severity=warning)`
  context that no workflow produces, which would block every merge. Delivered by #293 (merged as
  ac9cb1f3 through #295): `RequiredStatusChecksAreProducedTest` first, red, then the fix. The
  consumer-template side, where no job is named `QA Pipeline`, is Task 3.1 (#294).
- [ ] ⬜ **Task 2.2**: The template defects actionlint and zizmor report, fixed once the lane from
  Phase 4 is red on them:
  - the empty `choice` option;
  - the `github.head_ref` and input template injection;
  - excessive permissions;
  - `artipacked`;
  - unpinned `uses:`.

### Phase 3: CI made pragmatic (after Task 1.1)

- [x] ✅ **Task 3.1**: Delivered by #294 (#299, merged as 1b563aa3). The template runs once per PR
  push and on a push to `main`, `master` and `php8.5`, with:
  - `concurrency` with cancel-in-progress for pull requests only;
  - `contents: read` permissions;
  - the PHP version as a step;
  - a static job name, `QA Pipeline`;
  - Coverage Report opt-in.
- [x] ✅ **Task 3.2**: `ci.yml` retired and `update-deps.yml` pointed at `qa.yml` (#299). Not done: moving
  `ci.bash`'s settings into `qaConfig/qa.php` or the workflow `env:` (`update-deps.yml` still uses
  `ci.bash`). Caching is done: `qa.yml` caches Composer's files and `var/qa/cache`.
- [x] ✅ **Task 3.3**: `update-deps.yml` split into a read-only build and QA job and write jobs that run no
  project code (#299).
- [x] ✅ **Task 3.4**: Docs, branch-protection script comments, the `.claude/rules` path list and the
  CHANGELOG entry (#299).
- [ ] ⬜ **Task 3.5**: Owner decisions left by #299, as options on the pull request: the push trigger list,
  removing `AUTO_COMMIT_FIXES`, Infection in the required run (it hung twice in the duplicate run
  on #260), and the `qa-autofix.yml` gate name `QA gate (read-only qa)`. The first real
  `update-deps` run after #299 worked (four jobs; its pull request merged as 4366279a). The first
  `php8.5` push runs did not reach `Release`: each is cancelled at the 45-minute job timeout in a
  full Infection run, so `release.yml` is skipped and no release pull request is refreshed. That
  makes "Infection in the required run" a release-path decision, posted with options as
  [#303](https://github.com/LongTermSupport/php-qa-ci/issues/303); it stays open until the Owner
  chooses.

### Phase 4: actions-qa-ci (after Tasks 1.2 and 1.3)

- [x] ✅ **Task 4.1**: Design document, [design.md](design.md). Its open points are Tasks 1.4 to 1.8. It
  covers:
  - identifier scheme (`actionsqaci.<tool>.<upstream-id>`) and one owner per hazard class;
  - pinned-tool manifest;
  - justification lane;
  - entry point;
  - SARIF merge;
  - the toolchain, chosen for a runtime-free install on any Linux host (Task 1.2);
  - which DBF conventions are taken from php-qa-ci as conventions, not as code.
- [ ] ⬜ **Task 4.2**: Bootstrap the repository with pinned actionlint, zizmor and pinact, `rules`,
  `rule-doc` and the justification lane. The work is in
  [LongTermSupport/actions-qa-ci](https://github.com/LongTermSupport/actions-qa-ci), one pull
  request per slice, each passing a fresh sub-agent's verification and the required `CI` check
  before it is merged. Design §13 phases:
  - [x] Phase 1, repository floor: Go module, `dbf.json`, `version`, `help`, exit codes, README,
    CLAUDE.md, CHANGELOG, `ci.yml` (job `CI`) (pull request 1).
  - [x] Phase 2, core: the finding model and identifier scheme, the registry with the
    literal-identifier ban, the `text`/`agent`/`json` output formats, `rules`, `rule-doc`, `rule`,
    the generated identifier index and the page self-audit (pull requests 2 to 6); the `github`
    output format (pull request 17) and `check --sarif` (pull request 18).
  - [x] Phase 3, actionlint and ShellCheck: actionlint compiled in as sixteen rules, the pinned and
    verified ShellCheck fetcher, ShellCheck on the shell in `run:` steps through actionlint, and
    registry families for the ShellCheck codes (pull requests 7 to 9).
  - [ ] Phase 5, project record and justification lane, in part: the strict record loader and
    `actionsqaci.unjustifiedException` (pull request 10); the `check` command with file
    discovery, the lanes, `-t`, the output formats and its refusals (pull request 11); exceptions
    applied to findings by identifier, path, job and step, `actionsqaci.staleException`, and the
    narrowing shown by `rules` (pull request 12); `actionsqaci.inlineSuppression` and
    `actionsqaci.nativeSuppressionConfig` as guards that refuse a suppression made anywhere but in the record (pull request 14);
    `actionsqaci.templateDrift` with the record's `templates` section (pull request 15);
    `actionsqaci.requiredChecks` with the record's `requiredChecks` section, compared offline with
    the workflows (pull request 16); `actionsqaci.lintCoverage` with the record's
    `analyse.unanalysed` section, reading the repository's own index (pull request 19); and
    `analyse.include`, which has the lanes analyse tracked files outside the default set (pull
    request 20). Open: `disabledDefence` as a finding of its own, `versionPin`, the comparison of
    `requiredChecks` with real branch protection, and the record's `calibrations` section.
  - [x] Isolated lanes (pull request 13): the actionlint lane runs in a child process with a time
    limit and an output cap, so a crash or a hang is exit 4; the declared gap
    `actionlint-runs-in-process` now covers only the `rule` harness.
  - [ ] Phases 4, 6 to 9: zizmor, first-party checks and the CEL project rules, distribution,
    currency, dogfood. Phase 4 (zizmor) and phase 8 (currency) wait on Owner decisions 1.5 and 1.7
    ([#310](https://github.com/LongTermSupport/php-qa-ci/issues/310)). The next slices that need
    no decision are the open items of phase 5.
- [ ] ⬜ **Task 4.3**: First-party lanes:
  - [x] template drift (generalising the versionPins identity check): merged, pull request 15;
  - [x] workflow coverage outside `.github/`: merged, pull requests 19 and 20;
  - [x] required checks matching produced job names (from Task 2.1): merged, pull request 16; the
    comparison with real branch protection is a declared gap.
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
