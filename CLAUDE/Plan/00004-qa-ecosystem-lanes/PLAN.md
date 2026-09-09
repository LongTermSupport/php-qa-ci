# Plan 00004: QA ecosystem lanes

**Status**: In Progress
**Created**: 2026-09-09
**Owner**: joseph
**Priority**: Medium

## Overview

An ecosystem survey (report at `untracked/agent-reports/260909-qa-ecosystem-research-fable.md`,
verified against Packagist on the creation date) found eight maintained, PHP 8.5 compatible
tools that add a signal the pipeline does not have. Six are approved for adoption; two
(spaze/phpstan-disallowed-calls and shipmonk/dead-code-detector) are under review and are
tracked here as decisions, not tasks.

Every addition follows the pipeline's own rules: a `ToolInterface` lane or a PHPStan
extension wired through the phpstan lane, test-first, an identifier printed on failure, an
index row and a page under `docs/tools/`, and a builder method where the lane is opt-in.
Nothing is fetched at run time; PHARs go through PHIVE, PHPStan extensions through Composer.

## Goals

- `composer audit` runs inside the composerChecks lane and fails on a known advisory.
- phpstan/phpstan-deprecation-rules is a `require` dependency and in the default neon.
- shipmonk/composer-dependency-analyser runs as a linting lane; a decision recorded on
  whether it replaces composer-require-checker.
- tomasvotruba/type-coverage runs through the phpstan lane behind `withTypeCoverageFloors()`.
- phpcpd-next runs as an informational post-success lane next to phploc.
- vincentlanglet/twig-cs-fixer runs as a Symfony platform lane in the coding-standards phase,
  dry-run in a read-only run.
- The upgrade guide and CLAUDE.md list every new lane and builder method.

## Non-Goals

- Adopting phpstan-disallowed-calls or dead-code-detector before their review decision.
- Non-PHP toolchains (actionlint, markdownlint, codespell, editorconfig-checker).
- roave/backward-compatibility-check (deferred to a release-guard plan).
- Replacing composer-require-checker in this plan; only the evaluation is in scope.

## Tasks

### Phase 1: No new dependencies

- [ ] ⬜ **Task 1.1**: `composer audit` in `ComposerChecksTool` after diagnose: `--format=json`, fail on any advisory, print the abandoned-package list; test over a fixture lock with a known advisory.
- [ ] ⬜ **Task 1.2**: phpstan/phpstan-deprecation-rules from `suggest` to `require`; included in `configDefaults/generic/phpstan.neon`; this repository green under it.

### Phase 2: New lanes

- [ ] ⬜ **Task 2.1**: `ComposerDependencyAnalyserTool` (linting phase, after composerRequireChecker): shipmonk/composer-dependency-analyser as a PHIVE PHAR if published, else a Composer dependency; config resolved via `qaConfig/composer-dependency-analyser.php`; identifier, index row, docs page.
- [ ] ⬜ **Task 2.2**: Evaluation note in JOURNAL/: what composer-dependency-analyser reports that composer-require-checker does not, and the reverse, over this repository and one Symfony consumer; recommendation recorded.
- [ ] ⬜ **Task 2.3**: tomasvotruba/type-coverage as a Composer dependency wired through the phpstan lane; `withTypeCoverageFloors(int $return, int $param, int $property)` on the builder, off by default; the extension's error identifiers documented on the phpstan page.
- [ ] ⬜ **Task 2.4**: `PhpcpdTool` (post-success, informational, cannot fail): phpcpd-next via PHIVE; JSON output archived under var/qa; docs page.
- [ ] ⬜ **Task 2.5**: `TwigCsFixerTool` as a Symfony platform lane in the coding-standards phase (`ToolRegistry::platformLanes`); dry-run and `ReadOnlyGuidance` in a read-only run; config via `qaConfig/.twig-cs-fixer.php`; docs page.

### Phase 3: Decisions under review

- [ ] ⬜ **Task 3.1**: spaze/phpstan-disallowed-calls: decide between adopting it with a shipped deny-list that supersedes `ForbidDangerousFunctionsRule`, adopting it alongside with the two lists kept in sync by a test, or rejecting it. Record the decision in JOURNAL/ and either add the tasks or close this item.
- [ ] ⬜ **Task 3.2**: shipmonk/dead-code-detector: decide on adoption as an opt-in `withDeadCodeDetection(bool)`; confirm the PHAR-run PHPStan loads a Composer-installed extension. Record the decision in JOURNAL/.

### Phase 4: Documentation and gate

- [ ] ⬜ **Task 4.1**: `docs/upgrading-to-8.5.md` builder table, CLAUDE.md pipeline order and tools reference, `docs/pipeline.md`, `docs/phpstan-rules/README.md` lane rows for every new lane.
- [ ] ⬜ **Task 4.2**: Full read-only battery green; CI green; pushed.

## Success Criteria

- [ ] Every approved tool has a lane or extension, a test, an identifier, an index row and a docs page.
- [ ] `QA_READONLY=1 CI=true bin/qa` passes on this repository with every new lane on.
- [ ] Each review item has a recorded decision.

## Delivery & Milestones

- Plan opened: see the first commit touching this folder.
