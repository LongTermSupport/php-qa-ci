# Plan 00023: release dependency currency gate

**Status**: Not Started
**Created**: 2026-10-19
**Owner**: dev
**Priority**: High

## Overview

The Owner's requirement: every release is fully upgraded to the latest version of every
dependency, unless there is a specific reason to hold one back, and that reason is clearly
documented. Today nothing connects releasing to currency. The daily update (owned by
[Plan 00020](../00020-tool-currency-and-phpstan-turbo/PLAN.md): the update workflow, agents
merging green update pull requests per its Task 1.5, the PHPStan/Turbo parity, and the
`add-tool-updates` entries per its Task 4.1) has been red since #204 opened. Infection 0.35.7
and 0.35.8 never shipped, and `85.7.0` was released anyway. The measured state is in
[subagent-reports/261019-currency-facts-opus.md](subagent-reports/261019-currency-facts-opus.md).

This plan does not run `composer update` inside the release workflow. That would ship a lock and
PHARs that no QA run has seen. Instead currency becomes a **release gate**, a Defence Before Fix
detector for the class "a release ships a dependency older than its latest release, and no
recorded reason explains it". The release pull request cannot merge while anything is behind. The
remedy is the existing daily update, which the gate triggers. The only alternative to updating is
an entry in a committed **holdback register**, which states the reason, links the issue and
carries the Owner's sign-off. The gate also validates the register, so a holdback that is no
longer needed fails too.

Scope is limited to what Plan 00020 lacks: (a) the release-time gate and (b) the holdback
register. The gate covers every pinned source: the root `composer.lock`, each `build/<tool>/`
lock that feeds a self-built PHAR (the rebuild path from
[Plan 00002](../Completed/00002-phar-vendored-rector/PLAN.md)), the PHIVE PHARs in `phive.xml`,
PHPStan together with its Turbo binary as one unit, and `vendor-bin/shellcheck`
([Plan 00008](../Completed/00008-shellcheck-lane-vendored-binary/PLAN.md)).

## Goals

- A release pull request cannot be merged while any covered dependency is older than its latest
  stable release, unless a valid holdback covers it
- A failing gate starts the daily update (`update-deps.yml`), so the gate also drives the remedy
- Every holdback is recorded in one committed register: the subject, the range it is held to, the
  reason, an issue link and the Owner's sign-off. Each release's notes list the active holdbacks
- A holdback that no longer holds anything back (stale), or that has passed its review date,
  fails the gate
- The gate is a defence: a stable identifier `phpqaci.dependencyCurrency`, a page stating the
  correct construction, resolvable by `rule-doc`, and listed by `bin/rules`
- The gate fails closed: a source it cannot query is a crash (exit 2) and never a pass

## Non-Goals

- The daily update process, who merges its pull requests, or PHPStan/Turbo parity (Plan 00020)
- Running `composer update` or any fetch inside `release.yml`
- Fixing #204/#205 (Infection 0.35.7's `logs.debugEvents`): that is #205's own fix. This plan's
  gate would have blocked `85.7.0` on it
- A default-on pipeline lane (see Decision D1)
- `php8.3` (dead)

## Technical Decisions

- **D1: a `bin/` command plus a release-workflow step, not a default lane.** The
  [tool-boundaries](../../tool-boundaries.md) test passes questions 1 and 3. But a currency
  verdict changes with the registries, not the commit: as a lane it would turn every unrelated
  push and pull request red the moment any upstream released, and it would be the only lane that
  needs the network. So the gate is `bin/dependency-currency` (logic under
  `src/DependencyCurrency/`, the `InstallDecider`/`ShellCheckInstaller` split: pure decision
  classes with unit tests per branch, and I/O behind interfaces). Like `bin/changelog-release`,
  it is driven by the workflow. It is still a defence with an identifier and a page (OPEN
  QUESTION Q4 covers a consumer-facing opt-in lane).

- **D2: where the gate bites.** (1) `ci.yml` runs `bin/dependency-currency check` as a step of
  the required `QA Pipeline` job, only when the head branch is `chore/release-*`. A stale release
  pull request therefore cannot merge, and no other pull request is affected. (2) `release.yml`
  runs the same check after it opens or refreshes the release pull request. On failure it
  dispatches `update-deps.yml` (`actions: write` is already granted) and comments the report on
  the pull request. When the update lands green, the resulting push refreshes the release pull
  request and its CI re-runs the gate, so the loop closes without anyone acting. (3) The
  release-PR verification in [pr-verification.md](../../pr-verification.md) re-runs the check
  immediately before merging, which covers a release pull request left open after an upstream
  release.

- **D3: the "installed" side reuses `BundledToolVersions`** for `phive.xml`, ShellCheck and
  `build/<tool>/` locks, and adds the root `composer.lock`. The "latest" side is one source per
  kind behind an interface: Packagist for Composer packages (or `composer outdated --locked --format=json` per lock), the GitHub release that PHIVE resolves each alias to, and the GitHub
  latest release for ShellCheck. PHPStan is checked once, as `phive.xml`'s `phpstan`. Turbo is
  not a separate subject: `turbo-ext.json` must name the same version, which `PharToolsVerifier`
  already enforces and the gate re-asserts. "Latest" means the newest stable release, ignoring
  the constraint, so a constraint that excludes a newer major (or a newer `0.x` minor under
  `^0.35`) is reported as behind.

- **D4: the holdback register is `qaConfig/dependency-holdbacks.json`.** It is JSON so that
  workflows, the release-notes renderer and humans read one file without loading PHP. It
  carries a JSON schema, and the gate validates it strictly, refusing unknown keys. Shape:

  ```json
  {
      "holdbacks": [
          {
              "subject": "phive:infection",
              "heldTo": "<0.35.7",
              "reason": "0.35.7 adds logs.debugEvents, which the derived Infection config resolves wrongly until #205 lands",
              "issue": "https://github.com/LongTermSupport/php-qa-ci/issues/205",
              "approvedBy": "<Owner GitHub login>",
              "reviewBy": "2026-11-30"
          }
      ]
  }
  ```

  `subject` is one of `composer:<package>`, `build:<tool>:<package>`, `phive:<alias>`, or
  `shellcheck`. `heldTo` is a Composer-style constraint. `reason` follows the trailer rule (at
  least two words, not `wip`/`tbd`). `issue` must be an issue URL in this repository. A
  register entry fails as **stale** when the latest release satisfies `heldTo` (nothing is held
  back), when the subject no longer exists, or when the installed version is not the newest
  release within `heldTo` (a holdback holds a package to a range and does not freeze it). It
  fails as **expired** when the run date is past `reviewBy`.

- **D5: a holdback is an Owner decision, never a silent edit** ([CLAUDE.md](../../../CLAUDE.md),
  narrowing). `qaConfig/` is not a watched changelog path, so the gate makes the decision visible
  instead. The release notes gain a "Held back" section rendered from the register by
  `changelog-release notes`, and `approvedBy` must name a login listed as an Owner in the config
  (see Q5 for how that list is kept).

- **D6: exit codes.** `0` all current or validly held, `1` a finding (behind, stale or expired
  holdback, malformed register), `2` crash (a source unreachable or unparseable, or a rate limit
  hit). Output ends with the identifier line, as the lanes do. `--format=json` is for the
  workflow comment.

- **D7: excluded by construction.** Packages pinned to a branch (`dev-*`, e.g.
  `roave/security-advisories: dev-master`) have no release to compare, and platform packages
  (`php`, `ext-*`, `composer-plugin-api`) are not dependencies of this kind. Both are skipped by
  rule, not by register entry, and the page states it.

## Owner rulings

- **Q1: majors block.** A dependency behind its latest major (or a `0.x` minor under a `^0.x`
  PHIVE constraint) blocks the release unless a holdback entry with a reason and a review-by
  date covers it. Constraints are raised by hand; Task 5.2 stays optional.
- **Q2: direct dependencies block, transitive ones warn.** A transitive package is held where it
  is by the direct dependency that requires it, and php-qa-ci cannot force it higher, so the gate
  reports a stale transitive and does not fail on it. The Owner's premise is that no transitive
  package is load-bearing: anything php-qa-ci's code uses must be declared in `composer.json`.
  That is already enforced by two lanes, `composerRequireChecker` (a used symbol from an
  undeclared package) and `composerDependencyAnalyser` (shadow dependencies), so the gate does
  not re-check it. At the time of the ruling the root lock held 13 direct and 35 transitive
  packages.

## Open Questions (Owner decisions)

- **Q3: constraint floors in `composer.json`.** Consumers receive the constraints, not the lock.
  Should a release also require each `require` floor to be raised to the locked minor (e.g.
  `phpunit/phpunit: ^13.3` to `^13.4`)? That forces consumers up, and the changelog lane rules a
  tightened requirement `Changed — breaking`. Recommendation: no. Floors stay a deliberate edit,
  not a gate.
- **Q4: consumer-facing lane.** Option A (recommended): internal-first; ship the command and
  the identifier only, used by php-qa-ci's release. Option B: also an opt-in lane
  `dependencyCurrency` (off by default, like `changelog`, `-t dc`) for consumers who want the same
  rule, with the holdback register resolved through `ConfigPathResolver`. The cost of B is a
  permanent `-t` token, help line and network dependence for consumers. The benefit is that the
  release template (`templates/github-actions/release.yml`) ships the gate to consumers anyway,
  and B gives them a way to run it locally. Should the template step be on for consumers, opt-in,
  or php-qa-ci-only?
- **Q5: Owner sign-off evidence.** `approvedBy` is a typed login, and nothing mechanical proves
  that the Owner wrote it. Options: (a) a CODEOWNERS rule on `qaConfig/dependency-holdbacks.json`
  requiring the Owner's review (this needs branch protection to require code-owner review, which
  Plan 00019 set to zero approvals); (b) the gate checks that the commit that added the entry is
  the Owner's or was merged by the Owner (`gh api`); (c) trust the field plus pr-verification.
  Recommendation: (b).
- **Q6: `php8.4`.** It takes reported fixes only, so a currency requirement would force exactly
  the proactive work that ruling forbids. Recommendation: the gate runs only on the default
  branch's release line. A `php8.4` fix release is exempt and states so in its notes. Confirm.
- **Q7: `reviewBy` mandatory?** Recommendation: mandatory, at most one quarter ahead, so that a
  holdback is reconsidered rather than forgotten.

## Tasks

### Phase 1: record the class (Defence Before Fix 3.1)

- [ ] ⬜ **Task 1.1**: Write the class record in this folder (`dbf-record.md`). Class: a release
  ships a dependency older than its latest release with no recorded reason. Hazard sentence. Two
  independent search techniques: the tag history (each `85.N.N` tag's `phive.xml`/locks against
  the release dates of upstream versions), and reading the release path (`release.yml`,
  `changelog-release`) for any currency question. Bound it both ways (branch pins and platform
  packages are not instances). Runner-check status. Next wider rule: a currency check for
  consumer projects (Q4).
- [ ] 🔄 **Task 1.2**: Get the Owner's answers to Q1–Q7 and record them under Owner rulings
  before Phase 2 (Q1 and Q2 ruled; Q3–Q7 open).

### Phase 2: the detector, red first (TDD)

- [ ] ⬜ **Task 2.1**: `HoldbackRegister` + tests: parse and validate the register (schema,
  subject kinds, `heldTo` constraint parse, reason rule, issue URL, `reviewBy` date via the
  project clock and never `new DateTime`), each malformed case a named failure.
- [ ] ⬜ **Task 2.2**: `CurrencyDecider` (pure) + tests per branch: current, behind, behind but
  held, held and stale (latest satisfies `heldTo`), held but not newest within range, expired,
  subject gone, branch pin skipped, platform package skipped, major behind (blocks, per Q1),
  transitive behind (a warning, never a failure, per Q2), PHPStan and Turbo version mismatch.
- [ ] ⬜ **Task 2.3**: Installed-version readers: reuse `BundledToolVersions`; add the root
  `composer.lock` (direct/transitive per Q2), with fixture-based tests.
- [ ] ⬜ **Task 2.4**: Latest-version sources behind `LatestReleaseSourceInterface`: Packagist
  (`p2` metadata, stable only), PHIVE alias to GitHub release (decide by test whether to read
  `phar.io`'s repository list or map aliases from `phive.xml`; keys and unsigned entries as in
  `scripts/tool-install.bash`), ShellCheck GitHub release. Fakes in unit tests. One `Large` test
  against recorded HTTP fixtures. Network failure and rate limits are crashes (exit 2).
- [ ] ⬜ **Task 2.5**: `bin/dependency-currency check [--format=json]` entry point + command
  tests for the exit codes in D6 and the identifier line.
- [ ] ⬜ **Task 2.6**: Prove the net (3.3): run it on `origin/php8.5` and see it fire on the
  real state (Infection 0.35.6 against 0.35.8, the Symfony/PHPUnit patches). Record the output in
  `subagent-reports/`. Then show that a holdback entry for Infection, citing #205, turns the
  Infection finding green while the others stay red.

### Phase 3: identifier, page, registry

- [ ] ⬜ **Task 3.1**: `phpqaci.dependencyCurrency` as a class constant. Add the page
  `docs/tools/dependencyCurrency.md` (correct construction: update, or a valid holdback, and how
  to write one). Route it through `rule-doc` and list it in the identifier index
  (`docs/phpstan-rules/README.md`) and in `bin/rules` (so the CLAUDE.md active-defences region
  regenerates with it).
- [ ] ⬜ **Task 3.2**: Create `qaConfig/dependency-holdbacks.json` (empty `holdbacks`, or the
  Infection entry if #205 is still open and the Owner signs it) and its JSON schema.

### Phase 4: wire it into the release

- [ ] ⬜ **Task 4.1**: `templates/github-actions/release.yml`: after the release pull request is
  opened or refreshed, run the check. On a finding, dispatch `update-deps.yml` and comment the
  report on the pull request. Copy the template to `.github/workflows/release.yml`
  (`GitHubActionsTemplatesTest`). Consumer gating per Q4.
- [ ] ⬜ **Task 4.2**: `ci.yml`: a currency step in the `QA Pipeline` job, conditioned on a
  `chore/release-*` head branch, with `GH_TOKEN` for rate limits.
- [ ] ⬜ **Task 4.3**: `changelog-release notes` renders a "Held back" section from the register.
  Tests cover empty and non-empty registers.
- [ ] ⬜ **Task 4.4**: `pr-verification.md`: a release pull request's verifier re-runs
  `bin/dependency-currency check` immediately before the merge verdict.

### Phase 5: docs and follow-ups

- [ ] ⬜ **Task 5.1**: Docs. `CLAUDE/releases.md` gets a "A release is fully upgraded" section
  (the gate, where it bites, what to do when it fails). `CLAUDE/tool-currency.md` replaces the
  sentence "A tool is held back only by the Owner's decision..." with the register procedure.
  `CLAUDE.md`'s "Releases come from the changelog" and "Tools stay current" sections each get one
  sentence pointing to them. `docs/github-actions.md` covers the release template change.
  `CHANGELOG.md` gets an `Added` entry (the command and identifier) and, if the template step is
  on for consumers, a `Changed — breaking` entry.
- [ ] ⬜ **Task 5.2** (only if Q1 says automate): `update-deps.yml` attempts constraint bumps for
  majors in a separate pull request.

## Success Criteria

- [ ] `bin/dependency-currency check` exits 1 on `origin/php8.5` as found (Infection behind), and 0
  once the update lands or a signed holdback covers each finding
- [ ] A `chore/release-*` pull request whose dependencies are behind fails its required
  `QA Pipeline` check, and `release.yml` dispatched `update-deps.yml` for it
- [ ] A stale or expired holdback fails the gate. A malformed register fails with a named reason
- [ ] An unreachable registry exits 2 and never passes
- [ ] `rule-doc phpqaci.dependencyCurrency` prints the page, and `bin/rules` lists it
- [ ] The release notes of the first gated release list its holdbacks (or state there are none)
- [ ] Full battery green per [prepush-verification.md](../../prepush-verification.md),
  `GitHubActionsTemplatesTest` green

## Delivery & Milestones

- Facts gathered: [subagent-reports/261019-currency-facts-opus.md](subagent-reports/261019-currency-facts-opus.md)
