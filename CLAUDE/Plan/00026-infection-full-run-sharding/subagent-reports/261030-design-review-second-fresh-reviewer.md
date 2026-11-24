# Review 2 of PR #373: design for sharding the full Infection run (issue #344)

Scope: `CLAUDE/Plan/00026-infection-full-run-sharding/{DESIGN,PLAN,MEASUREMENT}.md` at
`origin/chore/plan-00026-design`, against the lane (`src/Pipeline/Lane/InfectionTool.php`,
`Infection/*`), `QaConfigBuilder`, `.github/workflows/qa.yml` (identical to the template), the
workflow tests, `composer.json`, and Infection 0.35.8 read statically (no PHP binary here, so nothing
was executed). DESIGN line numbers below are the design's own.

## Verified (the claim holds)

- Infection 0.35.8: `BasicSourceCollector::collect()` throws `NoSourceFound` on zero files (:67-76);
  `RunCommand` turns a filtered one into success (:398-402); `Engine::execute()` runs
  `runMutationAnalysis()` outside the `try/finally` (Engine.php:100-115); `FileMutationGenerator` returns
  silently for an untested file in covered-only mode (:88-90); `--filter` is deprecated since 0.34.0 and
  refused with positional paths or git options (SourceFilterOptions.php:133-137, 262-277);
  `RealPathFilterIterator` matches `preg_quote($value)` against `getRealPath()`; `PlainFilter` splits on
  `,`, trims, `array_filter`s.
- The JSON `stats` count keys the merger sums all exist (`JsonReporter.php:63-77`); `Calculator` formulas
  match what the design says it replicates (rounded `PHP_ROUND_HALF_UP`, timeouts detected unless
  `timeoutsAsEscaped`).
- Branch protection keys on the job name; `scripts/setup-branch-protection.bash` requires `QA Pipeline`
  and `tests/Small/RequiredStatusChecksAreProducedTest.php` is satisfied by exactly one non-matrix job with
  that `name:`. A matrix job reports `mutate (1)`, never colliding. Skipped jobs report success, so the
  gate-with-`always()` reasoning is right. `release.yml` keys on the workflow name and conclusion, which
  stay valid.
- MEASUREMENT arithmetic: 19,590/528 x 90 s = about 3,340 s (the "roughly 55 minutes"); 2,009/19,590 =
  10.3 percent; the "cap near 9 shards" arithmetic holds.
- `overwrite: true` exists on `upload-artifact` v4+; the template pins v7/v8. A job-level `if` is evaluated
  before the matrix is expanded (GitHub docs), so the empty-`fromJSON` guard is sound.

## Findings

### MAJOR-1: a shard's local verdict is not neutralised when the project's `infection.json` carries floors

DESIGN section 3 step 4 and 7, and section 0a last bullet: "No `--min-msi` ... Without floors Infection
exits 0 on any score." False for any config that sets the floors itself. In 0.35.8
`ConfigurationFactory.php:386-393` is `$minMsi ?? $schema->minMsi` and `$minCoveredMsi ?? $schema->minCoveredMsi`,
and `:401-403` does the same for `maxTimeouts`; `ignoreMsiWithNoMutations` (:379-383) likewise. The shipped
`qaConfig/infection.json` has none of these, but a consumer's resolved config (the lane resolves the
project's own via `context->configPath('infection.json')`, InfectionTool.php:208) may. Then every shard
judges its own partial score against the whole-tree floor (or counts its own timeouts against
`maxTimeouts`), exits non-zero, and the shard is `crashed` for a reason the join never sees; the reverse
(floor in config, but serial CLI overrides it) means the serial and sharded verdicts differ.
Fix: the derived shard config (`runConfig()` already writes a copy) removes `minMsi`, `minCoveredMsi`,
`maxTimeouts` and `ignoreMsiWithNoMutations` for a shard/coverage run, with a test; the join takes
`timeoutsAsEscaped` and the rounding precision from the same config, not from a "two decimals" assumption.

### MAJOR-2: the join cannot prove "no mutant lost" when a planned file is silently not selected

DESIGN section 4 steps 1 and 1a claim a join that "loses no mutant". The completeness check compares
`shard.json`'s recorded list with the recomputed plan, and 1a checks only the mutants that are present
against that list. Neither detects a planned file that Infection did not actually select, because
`RealPathFilterIterator` matches the argument against `getRealPath()` (symlinked workspace or project root,
a path from `index.xml` that is not a real path, case or normalisation) and an unmatched value is dropped
silently: other files in the shard still match, so no `NoSourceFound`, no empty log, no crash. The
serial run has no such failure mode, so this is new loss risk that nothing at run time observes; Task 2.0c
(7) and Task 3.1 test it once, on one runner.
Fix: add a runtime check that every planned file with executed lines either appears in the shard's log
(any status list) or is named as a zero-mutant file, and require the shard to compare the paths it passes
with `realpath()` before running (crash on a difference). A cheap form is a per-file mutant count in
`shard.json` derived from the log, checked in the join against `index.xml` having executed lines.

### MAJOR-3: `bin/infection-plan` is not reachable by a consumer's workflow

DESIGN section 5a "Registration" adds it only to the `qaConfig/qa.php` entry points. A consumer's template
calls `$(composer config bin-dir)/infection-plan`, and that exists only if `composer.json` `bin` lists it
(`composer.json:120-146` lists every other `bin/` tool). Neither DESIGN nor PLAN Task 2.7 names the
`composer.json` change (nor its repository tests), so the template would ship a step that fails for every
consumer who sets `PHP_QA_CI_INFECTION_SHARDS`. The scope job also installs dependencies before it can call
it, which adds a serial setup phase before `pipeline` can start (see MINOR-6).

### MINOR-1: the JSON log's real top-level keys differ from the design's list

DESIGN section 4 step 2 lists `killed, escaped, timeouted, notCovered, errored, syntaxErrors, skipped, ignored`. `JsonReporter.php:78-85` emits `escaped, timeouted, killed, killedByStaticAnalysis, errored, syntaxErrors, uncovered, ignored`: there is no `notCovered` or `skipped` list, and `killedByStaticAnalysis`
and `uncovered` are missing from the design. Fail-closed (the unknown-key rule would fail the join), and
Task 2.0c(5) pins a fixture, so not a blocker, but the written list is wrong and would make the first real
join fail. The same section's mutant identity "(file, line range, mutator, replacement text)" does not
exist: the entry has `mutatorName`, `originalSourceCode`, `mutatedSourceCode`, `originalFilePath`,
`originalStartLine` (JsonReporter.php:102-108), no line range.

### MINOR-2: several Infection line citations point past the end of the file

DESIGN section 0a cites `RealPathFilterIterator.php:167-172` and `:142-151` (the file has 84 lines; the
logic is at :53-60 and :78-83) and `NoSourceFound.php:297-308` (147 lines). The behaviours are right, the
anchors are not, which defeats the "settled from source" claim for the next reader. Re-cite.

### MINOR-3: open question 4 is answerable from source and should be closed

`MinMsiChecker.php:123-131`: the comparison is on the already rounded score, strict (`$msi < $minMsi`),
and a floor of 0 disables the check. `Calculator` rounds `PHP_ROUND_HALF_UP`; `KillJudgementDto::share()`
uses `HalfAwayFromZero` (identical for positive values). State it and drop the open question.

### MINOR-4: `shard.json` is written before the lane empties `var/qa/infection/`

DESIGN section 3 step 5 writes `shard.json` "next to the log", then runs the phar. The lane empties
`var/qa/infection` and deletes the JSON log immediately before the phar (InfectionTool.php:166-169), and
the project may name `logs.json` elsewhere (:233-235). The join's ordering hazard is spelled out; the
shard's is not. Say it is written after that step. Also record the shard's exit status in `shard.json`, so a
crashed shard whose artifact has a readable log (kept by `overwrite: true` if the rerun fails before
uploading) cannot look complete to the join even apart from the `needs` result check.

### MINOR-5: planner refusal "before any job starts" is impossible as written

DESIGN section 3 step 3: "A planned file count below N is refused by the planner before any job starts."
The planner needs `index.xml`, which exists only after the `infection-coverage` job. The refusal can only
happen in the coverage stage (preferred: fail there, before N shards start) or in each shard. Also the
empty-shard path (step 5) then says "only possible when the planner was bypassed": a file with executed
lines but no mutants (Task 2.0c(4) is still open) makes a non-empty shard with no log a crash that the
N-above-file-count refusal does not cover; the weight proxy plans such files.

### MINOR-6: workflow shape and existing tests

- `scope` first, then `pipeline needs: scope` (it needs `sharded` to force `useInfection=0`) adds a
  checkout, PHP setup and `composer install` to the critical path of every run, including unsharded ones; the
  "one extra short job" cost statement understates this. State it, or let `pipeline` decide `useInfection`
  itself.
- `tests/Small/PipelineTemplateRunsOncePerPushTest.php:112` pins `jobs->keys()` to `['qa', 'coverage-report']`
  with the message "a job of its own ... costs a runner and a check name", and :107-150 and :190-210 bind
  the id `qa` to the pipeline body (PHP detection step, timeout, `useInfection` env). The design reuses the id
  `qa` for the gate while moving that body to `pipeline`; the tests need a rewrite, not "extension"
  (DESIGN section 6 item 8). Say so, and say `coverage-report` must change `needs: qa` to `needs: pipeline`
  or keep it on the gate deliberately (section 5 says "untouched" and "keeps pointing at the job that
  uploads coverage", which contradict each other when the id `qa` moves).
- Gate `if: always()` keeps running after a workflow is cancelled (`cancel-in-progress` on a new push), so it
  would check out, install and attempt the join for a superseded run. `!cancelled()` still runs when needs
  fail or skip. "Declarative `if:` steps, no Bash logic" cannot fail a job without a `run: exit 1`; say so.
- The existing `noScriptOfAWorkflowOrTemplateHoldsAnExpression` rule (test :212) constrains the scope step:
  keep the expression out of `run:` (the design already does).

### MINOR-7: sizing may not fit hosted runners, and the equivalence proof needs a tolerance rule

The 3,300 s figure was extrapolated on a 22-CPU host at 11 threads; a hosted runner has far fewer CPUs, so
the shard count needed could pass the design's own "about 9" file-granularity ceiling (DESIGN section 1).
It is flagged as a risk, and Task 2.0a measures it, but the design hard-codes granularity = one file and no
fallback if 2.0a says N must exceed 9 (the file holding 2,009 mutants is then the floor). Likewise Phase 3
and the Large test compare "counts" with a serial run, while MEASUREMENT D and F show timeout counts (which
count as detected) move with load; define exactly which counts must be equal and which may differ.

### MINOR-8: `@api` `InfectionOptionsDto` and wording

`InfectionOptionsDto` is `@api`; a new `infectionShard` field must be an optional trailing parameter (say
so). DESIGN adds `LogicException` and a `with*()` builder; the architecture ruling
(CLAUDE/architecture.md:100-107) is met by the typed setting; question 1 is fairly answered yes.
Reuse of `phpqaci.infection` for "shard handoff broken" is consistent with tool-boundaries (an assertion in
an existing lane) but the join introduces a whole class of new failure texts; the page in `docs/tools/infection.md`
should list them (Task 2.8 names the doc, not the list).

## Repo-rule check

- Not a tool proxy: the setting is a lane setting with a typed builder, no Infection flag exposed. OK.
- PHP for logic, thin bin: planner and join are classes under `src/`, `bin/infection-plan` thin. OK, except
  MAJOR-3 (composer `bin`).
- DBF tests first: PLAN Task 2.1 is red tests before Tasks 2.2-2.8; the join, workflow-shape and merger
  property tests listed are good detectors. Missing detector: MAJOR-1 (config floors) and MAJOR-2 (silent
  non-selection).
- No suppressions, no baseline: none introduced.
- No time estimates in the plan: PLAN has none; measured durations only. OK.
- Public-repo wording: generic; no names, hosts or paths found beyond repository-relative ones.

## Rerun and artifact handling (answers to the focus question)

Sound as written: names `infection-shard-<i>` with `overwrite: true`, the gate runs only after the final
attempt's shards, the join pins commit, coverage hash and recomputed lists, and the 1-day coverage retention
failing loudly is acceptable. Residual risk is only MINOR-4. The "merge ref moved mid-run" worry does not
apply: `actions/checkout` pins `github.sha`.

APPROVE WITH MINORS is not warranted: CHANGES NEEDED. MAJOR: MAJOR-1 (config-level `minMsi`/`minCoveredMsi`/`maxTimeouts` leak into shards), MAJOR-2 (join cannot detect a planned file Infection silently did not select), MAJOR-3 (`bin/infection-plan` missing from `composer.json` `bin` so consumers cannot call it). BLOCKER: none.
