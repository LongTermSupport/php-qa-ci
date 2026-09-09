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
- phpcpd-next runs as the informational post-success lane; the abandoned phploc lane is gone.
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

- [x] ✅ **Task 1.1**: `composer audit` in `ComposerChecksTool` after diagnose: `--locked --abandoned=report`, fail on any advisory, abandoned packages reported; `withComposerAudit(false)` / `useComposerAudit=0` for an offline build.
- [x] ✅ **Task 1.2**: phpstan/phpstan-deprecation-rules from `suggest` to `require`; registered through phpstan/extension-installer; this repository green under it.
- [x] ✅ **Task 1.3**: `suggest` block audited against Packagist; four wrong entries removed; the manifest-decidable half enforced by `RedundantSuggestDetector` in the composerChecks lane.

### Phase 2: New lanes

- [x] ✅ **Task 2.1**: `ComposerDependencyAnalyserTool` in the linting phase after composerRequireChecker, alias `cda`. No PHIVE PHAR is published, so a Composer `require` (zero runtime dependencies of its own). Identifier, index row and docs page done.
- [x] ✅ **Task 2.2**: Evaluation recorded in JOURNAL/. Complements, not substitutes: require-checker reports nothing on this repository while the analyser found two dead `require` entries, and an unused dependency is invisible to require-checker by construction.
- [x] ✅ **Task 2.3**: tomasvotruba/type-coverage wired through the phpstan lane behind `withTypeCoverageFloors(?int $returnType, ?int $paramType, ?int $propertyType, ?int $constantType, ?int $declare)`, every floor off by default. All five error identifiers documented on the phpstan page. The lane now writes `parameters.paths` when the floors are on, without which the extension reports nothing at all.
- [x] ✅ **Task 2.4a**: `PhplocTool` deleted with its registry row, aliases, docs page and test. phploc has been abandoned since 2020 and was never shipped, so the lane could not run. Owner decision: drop the size metric, it is not wanted. No successor is sought.
- [ ] ⬜ **Task 2.4b**: `PhpcpdTool` (post-success, informational, cannot fail): phpcpd-next via PHIVE; JSON output archived under var/qa; docs page. It detects copy/paste, which is unrelated to what phploc measured — this is a new signal, not a replacement.
- [x] ✅ **Task 2.5**: `TwigCsFixerTool` as a Symfony platform lane in the coding-standards phase; `platformLanes()` now dispatches on phase. Check-only with `ReadOnlyGuidance` in a read-only run, `--fix` otherwise; config `.twig-cs-fixer.php`; PHAR via PHIVE; docs page.

### Phase 2b: raised in review, not from the ecosystem survey

- [x] ✅ **Task 2.6**: `RequireVariadicOverArrayParameterRule` — an `array` parameter whose element type lives only in a docblock should be `T ...$name`. Owner-raised. DBF 3.1 to 3.3 complete, rule committed red at `9c030cf`, 12 instances recorded.
- [ ] ⬜ **Task 2.7**: DBF 3.4 — convert all 12 instances and re-sweep to zero.

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
