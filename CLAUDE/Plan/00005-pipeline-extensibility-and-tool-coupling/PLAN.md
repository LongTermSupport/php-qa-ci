# Plan 00005: pipeline extensibility and tool coupling

**Status**: In Progress
**Created**: 2026-09-09
**Owner**: joseph
**Priority**: High

## Overview

Plan 00004 shipped the QA ecosystem lanes; `php8.5` is released, CI green, and now the
repository default branch. This plan carries the work deliberately deferred out of that
release, plus in-flight changes parked on a feature branch rather than pushed into it.

The centre of it is **pipeline extensibility**. Today the pipeline is a fixed list in
`ToolRegistry::shipped()` plus a Symfony special case in `platformLanes()`. A consuming project
can replace a lane via `qaConfig/tools/{name}.php` but cannot add one, cannot add a tool group,
and cannot reorder phases. The Owner's design steer is composition over inheritance: a
`PipelineBuilder` that starts from the defaults and extends them, or starts empty and is built
from scratch, with default tools supplied by enums for strict typing — the pipeline being a
construction rather than a hardcoded list.

That capability is the prerequisite for the Owner's chosen route to evaluating new tools:
install a candidate, dogfood it through a project-level extended pipeline against real code,
and bundle it only once it has earned its place. `shipmonk/dead-code-detector` is first in that
queue.

Development is moving into a consuming client project so real client-level issues can be fixed
against the vendored checkout — see "Working on php-qa-ci from a consuming project's vendor/"
in [CLAUDE.md](../../../CLAUDE.md).

## Current state (read this first when resuming)

**Everything is on `php8.5`, which is the repository default branch.** Phase 1 is done: the two
in-flight changes were verified by a full writable battery (exit 0, 840 tests, Covered Code MSI
82%) and merged with `--no-ff`. There is no unmerged work and no uncommitted work.

What landed, and why each was held back from the 00004 release rather than rushed into it:

| Change                                                                       | Why it exists                                                                                                                                  |
| ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| The variadic rule never suggests converting a parameter with its own default | A variadic cannot carry a default — there is no `string ...$items = ['a']` — so converting would silently drop a non-empty default             |
| `twigCsFixer` gated on `twig/twig` rather than on Symfony                    | The PHAR is standalone; gating on `symfony.lock` meant a Slim, Laravel or plain library project using Twig got no Twig coding standards at all |

**Start here**: Task 3.2 needs the Owner's bundling decision; Phase 4 is open. Phase 2 is done
and Task 3.1's evidence is in the journal. Task 2.1 landed `PipelineBuilder`, `PhaseDto` and an open
phase list on the registry (each phase derives its own `all*` runner; `ToolDefinitionDto::$phase`
is now the phase name). `QaApplication` already runs through `PipelineBuilder::defaults()`, so the
default pipeline is byte-for-byte the shipped one. Nothing in Phases 3–4 has been started.

## Goals

- Land the two in-flight commits on `php8.5` behind a full green battery.
- A consuming project can register extra tools and extra tool groups, and control group order,
  without forking the registry.
- A candidate tool can be dogfooded through a project-level extended pipeline before any
  bundling decision, with that process documented as the archetype.
- Skills and agent instructions point at a single source of truth instead of restating it.

## Non-Goals

- Changing the four-phase default order, or the tools in it. Defaults stay as 00004 shipped
  them; extensibility is purely additive.
- Adopting `shipmonk/dead-code-detector` into the shipped pipeline. This plan only reaches the
  point where that decision rests on evidence.

## Tasks

### Phase 1: land the in-flight work

- [x] ✅ **Task 1.1**: Full writable battery on the feature branch — green, exit 0, ALL TESTS
  PASSING, **Covered Code MSI 82%**, holding the floor exactly as the 00004 release did. The
  two changes removed as many mutants as they added, so no extra assertions were needed and
  the floor was not touched.
- [x] ✅ **Task 1.2**: Merged into `php8.5` with `--no-ff` and pushed.

### Phase 2: pipeline extensibility

- [x] ✅ **Task 2.1**: `PipelineBuilder`, composition over inheritance. `::defaults()` seeds the
  shipped registry, `::empty()` starts from nothing; immutable withers add a tool to a phase,
  add a phase, and set phase order, in the style of `QaConfigBuilder`.
- [x] ✅ **Task 2.2**: `ToolDefinitionDto` becomes `@api` — a consuming project must be able to
  construct one and it is currently `@internal`. Check what else on the construction path
  needs promoting with it.
- [x] ✅ **Task 2.3**: Wire the builder into the project config, and make the `-t` usage text and
  the characterisation test follow the constructed registry rather than a frozen literal. Landed
  as a sibling file, `qaConfig/pipeline.php`, rather than a second return from `qa.php` (see the
  journal for why).
- [x] ✅ **Task 2.4**: Document the extension process as the archetype, with a worked example:
  [docs/extending-the-pipeline.md](../../../docs/extending-the-pipeline.md) and
  `templates/qaConfig-pipeline.php`.

### Phase 3: tool evaluation by dogfooding

- [x] ✅ **Task 3.1**: Install `shipmonk/dead-code-detector` here and run it through a
  project-level extended pipeline against `src/`. Record findings and false-positive rate in
  JOURNAL/. PHAR-run PHPStan does load Composer-installed extensions — proven in 00004 via
  `vendor/phpstan/extension-installer/src/GeneratedConfig.php`.

  ```
  **Why this repository is an unusually good candidate.** The tool has no "this is a library"
  flag; its only documented entrypoint mechanism is `@api` phpdoc. For most libraries that
  means a large annotation sweep before the first useful run, because nothing internal calls
  the public API and it all reports as dead.

  We have already done that sweep, for an unrelated reason. `RequireApiOrInternalTagRule`
  forces every class in `src/` to declare `@api` or `@internal`, and the 26 `@api` classes
  are exactly the consumer contract — `QaConfigBuilder`, `ToolInterface`, `ToolContext`,
  `ToolResultDto`, `PhpInvoker`, `ProcessRunnerInterface`, the config DTOs, `ShippedTools`.
  So there is no untagged public surface to be misreported, and no reason to exclude
  php-qa-ci from its own run.

  Two things still to confirm when the task is picked up, neither a blocker:

  - Whether class-level `@api` is honoured for the class's public methods, or whether the
    tool wants the tag on each method. The recon read the README as class/interface/method,
    but that was not verified against the source.
  - `usageExcluders.tests.enabled` must be on, or a method reached only from tests counts as
    dead.

  `--error-format removeDeadCode` stays review-only regardless: it deletes code, and on a
  library a false positive means deleting published API.
  ```

- [ ] ⬜ **Task 3.2**: Decide on bundling as an opt-in `withDeadCodeDetection(bool)` on the
  evidence from 3.1. Record the decision either way. **Owner's call**: the evidence and a
  recommendation are in the journal (18:58 entry); the lane stays `-t dcd` only until then.

### Phase 4: coupling and SSoT debts carried from 00004

- [ ] ⬜ **Task 4.1**: Audit the remaining platform coupling. `twigLint` and `yamlLint` invoke
  `bin/console`, so they are genuinely Symfony-coupled — but confirm no standalone linter
  would free them the way the PHAR freed `twigCsFixer`, and decide whether `yamlDirectories`
  should default off Symfony as `twigDirectories` now does.
- [ ] ⬜ **Task 4.2**: The "skills should be pointers" refactor. The `qa` skill is 509 lines and
  carries context belonging in `CLAUDE/` docs. `CLAUDE/prepush-verification.md` is the model.
- [x] ✅ **Task 4.3**: A PHPStan rule for the other half of the `array<T>` finding: `T[]` and
  `array<T>` state nothing about keys, so a docblock meaning a list should say `list<T>`.
  This is why the variadic rule deliberately does not match `T[]`; see
  [its docs page](../../../docs/phpstan-rules/require-variadic-over-array-parameter.md).
  Only 5 `T[]` parameters exist repo-wide, so the sweep is small.

## Success Criteria

- [ ] A consuming project adds a tool and a tool group from `qaConfig/qa.php`, with no fork.
- [ ] The default pipeline behaves identically to the one 00004 shipped.
- [ ] `dead-code-detector` has a recorded decision backed by a dogfooding run, not an opinion.
- [ ] Full writable battery green on `php8.5`, MSI at or above 82%, CI green.

## Delivery & Milestones

- Branched from the 00004 release, `php8.5` at `085f595`.
- In flight: `cb547a1`, `0973cc6` on `feature/twig-decoupling-and-variadic-narrowing`.
