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

**Released and safe to build on**: `php8.5` at `085f595` — CI green, repository default branch,
full writable battery green, Covered Code MSI 82%.

**In flight** on `feature/twig-decoupling-and-variadic-narrowing`, branched from `085f595`:

| Commit    | What                                                                           |
| --------- | ------------------------------------------------------------------------------ |
| `cb547a1` | The variadic rule never suggests converting a parameter that has its own default |
| `0973cc6` | `twigCsFixer` gated on `twig/twig` rather than on Symfony                       |

Both are unit-green (840 tests) and `allStatic` green. **Neither has had a full battery run**,
so their effect on the mutation floor is unverified. That is Task 1.1.

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

- [ ] ⬜ **Task 1.1**: Full writable battery on the feature branch; confirm Covered Code MSI is
      still at or above 82%. Both commits changed rule and registry behaviour, so escaped-mutant
      counts will have moved. If the floor is missed, add assertions for the new behaviour — the
      floor is Owner-only and is not to be lowered.
- [ ] ⬜ **Task 1.2**: Merge the feature branch into `php8.5` with `--no-ff`, push, confirm CI.

### Phase 2: pipeline extensibility

- [ ] ⬜ **Task 2.1**: `PipelineBuilder`, composition over inheritance. `::defaults()` seeds the
      shipped registry, `::empty()` starts from nothing; immutable withers add a tool to a phase,
      add a phase, and set phase order, in the style of `QaConfigBuilder`.
- [ ] ⬜ **Task 2.2**: `ToolDefinitionDto` becomes `@api` — a consuming project must be able to
      construct one and it is currently `@internal`. Check what else on the construction path
      needs promoting with it.
- [ ] ⬜ **Task 2.3**: Wire the builder into `qaConfig/qa.php`, and make the `-t` usage text and
      the characterisation test follow the constructed registry rather than a frozen literal.
- [ ] ⬜ **Task 2.4**: Document the extension process as the archetype, with a worked example.

### Phase 3: tool evaluation by dogfooding

- [ ] ⬜ **Task 3.1**: Install `shipmonk/dead-code-detector` here and run it through a
      project-level extended pipeline against `src/`. Record findings and false-positive rate in
      JOURNAL/. PHAR-run PHPStan does load Composer-installed extensions — proven in 00004 via
      `vendor/phpstan/extension-installer/src/GeneratedConfig.php`.

      **Sequencing constraint found in the 00004 recon**: on a library, the tool has no "this is
      a library" flag. Its only documented mechanism is `@api` phpdoc marking entrypoints, so
      without that sweep first it reports our entire public surface as dead — every
      `ToolInterface` lane, every `QaConfigBuilder::with*()`, every hook signature — and the run
      is uninterpretable rather than merely noisy. Also enable `usageExcluders.tests.enabled`,
      or a method reached only from tests counts as dead. `--error-format removeDeadCode` exists
      and must not be run before the sweep: it would delete public API.

      This is why 3.1 sits after Phase 2 rather than beside it. Task 2.2 already has to mark the
      construction path `@api` for the builder to be usable from a consumer, so the annotation
      work is shared rather than duplicated.
- [ ] ⬜ **Task 3.2**: Decide on bundling as an opt-in `withDeadCodeDetection(bool)` on the
      evidence from 3.1. Record the decision either way.

### Phase 4: coupling and SSoT debts carried from 00004

- [ ] ⬜ **Task 4.1**: Audit the remaining platform coupling. `twigLint` and `yamlLint` invoke
      `bin/console`, so they are genuinely Symfony-coupled — but confirm no standalone linter
      would free them the way the PHAR freed `twigCsFixer`, and decide whether `yamlDirectories`
      should default off Symfony as `twigDirectories` now does.
- [ ] ⬜ **Task 4.2**: The "skills should be pointers" refactor. The `qa` skill is 509 lines and
      carries context belonging in `CLAUDE/` docs. `CLAUDE/prepush-verification.md` is the model.
- [ ] ⬜ **Task 4.3**: A PHPStan rule for the other half of the `array<T>` finding: `T[]` and
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
