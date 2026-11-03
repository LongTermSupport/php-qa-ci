# Design revision 3 report: Plan 00026

Scope: revise [DESIGN.md](../DESIGN.md) and [PLAN.md](../PLAN.md) for the second independent review,
[261030-design-review-second-fresh-reviewer.md](./261030-design-review-second-fresh-reviewer.md). Infection 0.35.8 was
read statically (no PHP binary available); every cited line was checked against the 0.35.8 tag.

## Majors

- MAJOR 1 (floors in the configuration): `ConfigurationFactory.php:386-404` and `:379-384` apply `minMsi`,
  `minCoveredMsi`, `maxTimeouts` and `ignoreMsiWithNoMutations` from the file when the command line omits them. The
  derived shard configuration now has those four keys removed (section 3, step 1: a pure `unset` on the decoded copy
  `runConfig()` already writes); the join reads the floors, `timeoutsAsEscaped` and the rounding precision from the
  original resolved configuration, and judges `maxTimeouts` on the merged count. Pinned by a strip test (Task 2.1,
  DESIGN section 6, item 8a).
- MAJOR 2 (silent non-selection): `RealPathFilterIterator` drops an unmatched value silently. Two layers: the shard
  checks every path equals its own `realpath()` before running (section 3, step 3), and the join requires per-file
  evidence (section 4, step 1b): `perFile` counts derived from the log, `zeroMutant` listed, keys equal to the
  recomputed plan, sum equal to the log. A planned file with executed lines in neither the log nor an accepted
  zero-mutant list fails the join.
- MAJOR 3 (registration): `bin/infection-plan` goes in `composer.json` `bin`, `.gitignore` and the executable bit
  (all pinned by the existing `ComposerBinManifestTest`) as well as the `qaConfig/qa.php` list; a consumer calls
  `$(composer config bin-dir)/infection-plan` after `composer install` (section 5a, Task 2.7).

## Minors

- JSON log keys corrected to `JsonReporter.php:78-85` (`killedByStaticAnalysis`, `uncovered`; no `notCovered` or
  `skipped`), and mutant identity restated from the real entry fields.
- Citations re-anchored: `RealPathFilterIterator.php:53-61` and `:78-83`, `NoSourceFound.php:119-125`.
- Open question 4 closed from source: strict `<` on the rounded score, floor 0 off (`MinMsiChecker.php:123-131`,
  `Calculator.php:145-148`; the precision is configuration).
- `shard.json` is written after the lane empties its output directory, to a directory the lane never empties, in two
  steps (`running`, then `finished` with exit status and per-file counts).
- The "N above the file count" refusal moved to the coverage stage, since `index.xml` exists only after it.
- Gate uses `!cancelled()` with explicit result checks and one `run: exit 1`; `coverage-report` needs `pipeline`.
- `PipelineTemplateRunsOncePerPushTest` rewrite listed as Task 2.1a; the `scope` job's serial cost is stated, with the
  alternative of deciding `useInfection` inside `pipeline`.
- Hosted-runner sizing is stated as unproven and tied to Task 2.0a; Phase 3 equivalence has a tolerance rule (timeouts
  may move between killed and timeouted; the set, the escaped list and the verdict must be equal).
- `@api` `InfectionOptionsDto` gets an optional trailing parameter; `docs/tools/infection.md` lists the join failures.

## Unresolved

- The evidence rule for a planned file that yields no mutants is fail closed until Task 2.0c (8) finds whether
  Infection leaves any per-file trace; Task 2.0a counts how many such files this repository has. If it has some and no
  trace exists, the design needs another idea (not available from the source reading).
- Whether sharding fits hosted runners at all is for Task 2.0a to say.
