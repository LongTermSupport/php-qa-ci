# Plan 00003: PHP pipeline rewrite

**Status**: In Progress
**Created**: 2026-09-08
**Owner**: joseph
**Priority**: High

## Overview

php-qa-ci's orchestration layer is hand-written Bash that evolved rather than being
designed: `bin/qa`, `includes/**` and the lock/timing modules total about 3,700 lines,
consumer configuration is arbitrary Bash sourced into the running shell, and the
top-level flow has no runtime tests. The php8.5 branch is the only major cut-over this
repository gets before 8.6, and it is not yet in use anywhere, so the rewrite ships with
the PHP 8.5 switch.

All real functionality moves into TDD PHP 8.5 under `LTS\PHPQA\Pipeline`: typed
configuration, a declarative tool registry, one process runner, one lock, and one tool
class per lane. Bash survives only as thin wrappers (`ci.bash`) and the maintainer
scripts under `scripts/`. There is no backwards compatibility with the Bash consumer
contract; a consuming project upgrading to this branch follows a written migration
guide for its `qaConfig/`.

Quality bar: PHPStan max with the bundled DBF rules, every class test-first, Infection
floors held, and every lane printing a stable identifier that `bin/rule-doc` resolves.

## Goals

- `bin/qa` is a PHP entrypoint with the same CLI surface: `-t <tool|alias>`, `-p <path>`
  (or bare path), `--json` (phpstan), `-h`; same aliases, phases, gates and exit codes.
- Every lane is a `ToolInterface` implementation with its own unit tests; in-process
  checks call their PHP class directly instead of spawning `bin/<check>`.
- One `ProcessRunner` (symfony/process) with the no-Xdebug ini, memory limit, streamed
  output and log archival; one `RunLock`; one retry/aggregate/read-only policy.
- Consumer configuration is PHP: `qaConfig/qa.php` (typed builder), `qaConfig/tools/<name>.php`
  overrides, `qaConfig/hookPre.php` / `hookPost.php`. The Bash forms are detected and
  fail with migration guidance.
- Environment variable names (`CI`, `QA_READONLY`, `phpqaMemoryLimit`, `useInfection`,
  `infectionDiffBase`, ...) keep their meaning so CI scripts need no change.
- The Bash orchestration (`includes/**`, `bin/qa`'s Bash body) is deleted; ShellCheck
  covers what remains.
- `docs/upgrading-to-8.5.md` tells a consumer exactly how to migrate its `qaConfig/`.
- Every lane prints `phpqaci.<lane>` and is listed by `bin/rules` with identifier and
  doc route, closing the corresponding `composer.json` known-gaps.

## Non-Goals

- Rewriting `scripts/` (deploy-skills, tool-install, build-rector-phar, branch
  protection): maintainer tooling, stays Bash.
- Changing which tools run, their order, or their configuration defaults.
- Supporting the old `qaConfig.inc.bash` / `tools/*.inc.bash` / `hook*.bash` forms.
- Porting the ETA/timing feature; the lock keeps a last-activity timestamp only.

## Context & Background

The authoritative contracts to preserve are
`tests/Small/Pipeline/ToolRegistryCharacterisationTest.php` (aliases, path support,
phase order) and `includes/options.inc.bash` (CLI). Existing in-process checks under
`src/` (Psr4Validator, PackageType, ConfigTemplateIgnoreList, InfectionConfig,
VersionPins, SensitiveParameter, Markdown, PHPStan/ProjectRecord) become lanes without
subprocesses. Design notes and dead-ends go in `JOURNAL/`.

## Tasks

### Phase 1: Foundations

- [x] ✅ **Task 1.1**: Dependencies: `symfony/process`, `symfony/console`; composer-require-checker config; PHPStan self-check stays at max.
- [x] ✅ **Task 1.2**: `Pipeline\Config`: `QaConfigDto`, `QaConfigBuilder` (immutable withers; derivations in `build()`), `EnvironmentReader` (existing env var names), `PlatformDetector`, `ConfigPathResolver` (3-level), `ProjectPathsResolver` (src/tests/bin/var/cache/phar discovery).
- [x] ✅ **Task 1.3**: `Pipeline\Tool`: `ToolInterface`, `PhaseEnum`, `ToolOutcomeEnum`, `ToolResultDto`, `ToolContext`, `ToolRegistry` with the frozen aliases/phases/gates/banners; the characterisation test re-targeted at the PHP registry.
- [x] ✅ **Task 1.4**: `Pipeline\Process`: `ProcessRunnerInterface`, `SymfonyProcessRunner`, `PhpInvoker` (no-Xdebug ini, memory limit, `PHP_QA_CI_PHP_EXECUTABLE`), `FakeProcessRunner` for tests, `LogArchiver`.
- [x] ✅ **Task 1.5**: `Pipeline\Lock\RunLock`: JSON lock file under `qaConfig/.qa-lock/`, stale detection by last-activity age, release on exit.
- [x] ✅ **Task 1.6**: `Pipeline\Runner`: `Pipeline` orchestrator (preflight, hooks, phases, single tool, retry prompt when interactive, aggregate mode, exit code), `ToolExecutor`, `AggregateReport`, `DirectoryPreparer`, `PharToolsVerifier`, `HookRunner`, `ShippedToolLocator`.
- [x] ✅ **Task 1.7**: `bin/qa-php` PHP entrypoint (renamed to `bin/qa` in Phase 5) via `QaApplication` + `ArgumentsParser` with the existing option contract and usage text derived from the registry; `--json` sends decoration to stderr.
- [x] ✅ **Task 1.8**: Large characterisation test `tests/Large/Pipeline/QaEntrypointTest.php`: `-h`, invalid tool, `-p` gate, path outside root, run header and the legacy-config guard; each ported lane adds its own case.

### Phase 2: In-process lanes

- [x] ✅ **Task 2.1**: `Psr4Validate`, `PackageType`, `ConfigTemplateIgnoreList`, `InfectionConfigSourceDirs`, `VersionPins`, `PhpstanIgnoreJustification`, `SensitiveParameterUsage`, `MarkdownLinks` as `ToolInterface` classes under `Pipeline\Lane` calling the existing PHP checks; every one prints an identifier, indexed in `docs/phpstan-rules/README.md` with a page under `docs/tools/`.
- [x] ✅ **Task 2.2**: `PhpStrictTypesTool` (scan + fix, read-only aware) and `BranchNamePolicyTool` (`GitBranches` probes via ProcessRunner, `BranchNamePolicyConfig` via nette/neon, pure `BranchNamePolicyDecision`); both print identifiers with index rows.
- [x] ✅ **Task 2.3**: The `bin/<check>` entrypoints stay for standalone use; the PHP pipeline calls the checks in-process.

### Phase 3: Subprocess lanes

- [x] ✅ **Task 3.1**: `PhpLintTool`, `ComposerChecksTool` (diagnose, normalize dry-run in read-only, dump-autoload), `ComposerRequireCheckerTool`, `PhplocTool`.
- [x] ✅ **Task 3.2**: `PhpstanTool` (parallel neon wrapper, text and `--json` on the real stdout, crash re-run, tautology note, log archival), `PhpArkitectTool` (env exports, crash threshold).
- [x] ✅ **Task 3.3**: `RectorTool` (safe, phpunit, project, php85 passes; dry-run in read-only; writable advisory) and `PhpCsFixerTool` (dry-run exit 8, lint-error detection); shared `ReadOnlyGuidance`.
- [x] ✅ **Task 3.4**: `PhpunitTool` (+ pure `PhpunitArguments`) and `InfectionTool` (+ pure `InfectionArguments`, `InfectionDiffFilter`): coverage reuse or generation, diff mode with committed-history filter, MSI floors, 100% advisory, low-priority phar run.
- [x] ✅ **Task 3.5**: Symfony platform: `TwigLintTool`, `YamlLintTool` as platform lanes appended to the linting phase; twig/yaml directories on the builder.

### Phase 4: Consumer configuration and migration

- [x] ✅ **Task 4.1**: `qaConfig/qa.php` builder contract, `qaConfig/tools/<name>.php` overrides, `hookPre.php` / `hookPost.php`; hard failure with guidance when the Bash forms are present.
- [x] ✅ **Task 4.2**: This repository's own `qaConfig/` migrated; `templates/` gain a `qaConfig-qa.php` template; `deploy-skills` manifest updated.
- [x] ✅ **Task 4.3**: `docs/upgrading-to-8.5.md` migration guide; `docs/configuration.md`, `docs/pipeline.md`, `docs/phpqa-tools.md`, README and CLAUDE.md rewritten for the PHP pipeline.
- [x] ✅ **Task 4.4**: `bin/rules` lists lanes from the PHP registry with identifier and doc route; `composer.json` known-gaps updated to what still holds.

### Phase 5: Remove the Bash orchestration

- [x] ✅ **Task 5.1**: Delete `includes/**`, the Bash `bin/qa` body, `lock`/`timing` modules; `ci.yml` ShellCheck scope reduced to what remains.
- [x] ✅ **Task 5.2**: Tests that parsed Bash (`ToolFragmentLivenessTest`, `SpecifiedPathNormalisationTest`, `FailureOutputNamesTheMethodTest`) deleted; `BinStubConsolidationTest` kept (the bin redirect stubs remain Bash); `tests/Large/Infection/InfectionDiffModeTest.php` replaced by the `InfectionTool` unit tests over `InfectionDiffFilter`; `QaEntrypointTest` runs over a fixture consumer so it never takes this repository's lock.

### Phase 6: Quality gate

- [ ] ⬜ **Task 6.1**: Full read-only battery green under PHP 8.5; Infection floors raised to the new measured score.
- [ ] ⬜ **Task 6.2**: GitHub CI green; branch pushed; `CLAUDE/prepush-verification.md` updated for the PHP entrypoint.

## Dependencies

- PHP 8.5 in the container and CI; PHPUnit 13; PHPStan 2.2 (phar); symfony/process and symfony/console 8.x.

## Technical Decisions

### Decision 1: `bin/qa` becomes a PHP script, not a Bash shim

The Composer bin proxy handles PHP scripts natively, which removes the proxy-parsing
block in the old `bin/qa`. The library directory is `__DIR__`, the project root is the
parent of the discovered `vendor/autoload.php` directory.

### Decision 2: In-process checks are called directly

Lanes whose logic already lives in `src/` (PSR-4, package type, version pins, ...) are
invoked as PHP calls, not subprocesses. Their `bin/` entrypoints remain for standalone
use and for the `rule-doc`/`rules` commands.

### Decision 3: Consumer configuration is a typed builder

`qaConfig/qa.php` returns `static function (QaConfigBuilder $qa): void`. Every setting
the Bash file could override is a typed method; unknown or misspelt settings fail at
load time instead of silently doing nothing.

### Decision 4: Environment variable names are kept

`CI`, `QA_READONLY`, `QA_FAIL_FAST`, `phpqaMemoryLimit`, `phpqaQuickTests`,
`phpUnitCoverage`, `useInfection`, `infectionDiffBase`, `PHP_QA_CI_PHP_EXECUTABLE` and
the rest keep their names and meanings; they are the CI-facing contract and cost
nothing to preserve.

### Decision 5: Timing/ETA is dropped

The lock records a last-activity timestamp used for stale detection. Historical timing
and ETA estimation (317 lines of Bash plus `jq` and GNU `date` dependencies) is not
ported.

## Success Criteria

- [ ] `QA_READONLY=1 CI=true bin/qa` passes on this repository with the PHP pipeline.
- [ ] `ToolRegistryCharacterisationTest` passes against the PHP registry with its golden constants unchanged.
- [ ] No `.inc.bash` remains under `includes/`; the ShellCheck job covers only `ci.bash`, `scripts/`, `git-hooks/`.
- [ ] Every lane prints `phpqaci.<lane>` on failure and `bin/rule-doc` resolves it.
- [ ] `docs/upgrading-to-8.5.md` exists and a fixture consumer with the old Bash `qaConfig/` fails with the guidance it names.
- [ ] Covered-code MSI at or above the current floor.

## Risks & Mitigations

- **Behaviour drift in the port**: the Large characterisation test over a fixture project is written in Phase 1 and extended as each lane lands.
- **Scope**: lanes are independent once the framework exists; Phases 2 and 3 fan out to subagents with one lane per agent and disjoint files.
- **Consumer breakage**: deliberate; mitigated by the hard failure with guidance and the migration guide.

## Delivery & Milestones

- Plan opened: 52bd6d1
