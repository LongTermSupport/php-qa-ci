# Design: sharding the full Infection run

Design only (Plan 00026, Task 1.1 and 1.2). Nothing here is built. Inputs: [PLAN.md](./PLAN.md),
[MEASUREMENT.md](./MEASUREMENT.md), issue #344 (option 3, "shard the full run"; same root as #303), the Infection lane
(`InfectionTool`, `Infection/*`), `.github/workflows/qa.yml` and `templates/github-actions/php-qa-ci.yml` (identical
today; the single job `QA Pipeline` has the 45 minute `timeout-minutes` limit).

## 0. Shape in one picture

```text
scope ──(full run?)──► coverage ──artifact──► shard 1..N ──artifacts──► QA Pipeline (gate: join + verdict)
  └────────────────► pipeline (all other lanes, useInfection=0 when sharded) ──────────────┘
```

- `scope`: one cheap PHP step that answers "would the Infection lane be a full run?" with the lane's own logic.
- `coverage`: runs the lane's own coverage generation once and uploads it.
- `shard i/N`: the lane, told to mutate only shard i's files from the downloaded coverage, judging nothing.
- gate job, named `QA Pipeline` (the one required status): joins the shard logs, judges them against the unchanged
  floors, and fails if any needed job failed or is missing.
- When the lane is not a full run, or sharding is off, the run is today's single job; the matrix jobs are skipped.

## 1. Shard unit, weight and balancing

**Unit: one source file.** Evidence: Infection generates mutants per file, independent of other files, so the union of
per-file runs is the serial mutant set; `--filter=a.php,b.php` mutated exactly those files (MEASUREMENT F). Infection's
CLI cannot split inside a file. The largest file holds 2,009 of 19,590 mutants (10.3 percent), so with N up to about 8
no single file exceeds the average shard and file granularity balances well. Above about 9 shards the largest file
becomes the floor of the slowest shard, so the design caps N (open question 3).

**Weight: covered executable lines from the coverage XML, computed free in every shard.** The candidates:

- A whole-tree dry run costs 661 s (MEASUREMENT): rejected.
- A committed weights file rots, churns on every source change, conflicts between branches, and needs a changelog or
  trailer on each refresh: rejected.
- Source size (bytes or lines) counts comments, docblocks and uncovered code, none of which produce mutants (the full
  run mutates covered code only).
- `coverage-xml/index.xml` already lists, for every file, `<totals><lines executable=".." executed=".."/>`. Every
  shard has it after the handoff, parsing is cheap, and it measures exactly "covered code", the only code a full run
  mutates. Weight = `max(1, executed)`.

The proxy is not proven: mutants per executed line vary by file kind (the 2,009-mutant collector is probably
expression dense). Task 2.0b measures the correlation on one real CI baseline before any code ships. The fallback,
still cheap and still no committed file: the gate job writes `infection-weights.json` (`file => mutant count`, summed
from the merged log) as an artifact of the default-branch run; a shard downloads the newest one when it exists and uses
the real count for a file whose content hash is unchanged, the proxy otherwise. This stays a refinement: the join never
depends on it.

**Algorithm: longest-processing-time greedy, deterministic.** Sort files by (weight descending, path ascending); give
each to the shard with the least load so far, ties to the lowest index. It is a pure function of (files with weights, N),
so every shard computes the same plan independently from the same coverage; no plan artifact and no coordination.
Files absent from `index.xml` (no covered code) have no mutants in a full run and are in no shard, which is also what
the serial run does. LPT guarantees a makespan within 4/3 of optimal and is typically within a few percent for 300
files. The fixed per-process start cost (15 to 20 s, MEASUREMENT F) is paid once per shard, which is why N is a handful
and not dozens.

Sizing: the serial mutation work was extrapolated to about 3,300 s on a loaded 22-CPU host (not measured on a CI
runner). N is chosen so a shard's mutation time sits well inside the job limit; for this repository that is about 6 to
8, fixed by the real baseline of Task 2.0a and kept in one place (section 5).

## 2. Coverage: generated once, handed to shards

- Stage `coverage` runs the lane's existing `ensureCoverage()` (the same phpunit invocation, config and Xdebug mode as
  a serial run, so the coverage is identical) plus `checkPreloadedSource()` over the whole source, then ends with
  `passed`. A red suite fails here exactly as in a serial run.
- Uploaded as one artifact (retention 1 day): `var/qa/phpunit_logs/coverage-xml/` and `var/qa/phpunit_logs/phpunit.junit.xml`
  (the lane hands Infection `--coverage=<logsDir>`, so Infection reads these), plus `coverage-manifest.json` written by
  the stage: commit SHA, project root and a hash of `index.xml`.
- Absolute paths: `index.xml` carries `source="<abs>/src"` and the junit file carries absolute test file paths
  (checked on a local coverage run). Both are valid only at the same checkout path. GitHub-hosted runners check out at
  the same workspace path in every job of one workflow, so the artifact works as is. The stage turns this assumption
  into a check: a shard crashes with a named message when its project root differs from the manifest's (a self-hosted
  layout), never trusting the coverage silently. Rewriting paths was rejected: more code, and Infection reads them in
  places we do not control.
- Infection trusts stale coverage silently (MEASUREMENT E). A shard compares the manifest SHA to `HEAD` and the index
  hash to the downloaded files, and crashes on a mismatch.
- A shard never regenerates absent coverage: it crashes ("shard mode needs the coverage the coverage stage
  produced"), because a quiet fallback would run N full coverage generations and hide a broken handoff.

## 3. A shard through the existing lane

**One new lane setting**, in the lane's existing style (environment name like `infectionDiffBase`, builder method like
`withInfectionDiffBase()`, a field on `InfectionOptionsDto`):

- `infectionShard`, grammar `coverage` | `<i>/<n>` (1-based, `1 <= i <= n`) | `join/<n>`; builder `withInfectionShard()`.
  Parsed into a small `InfectionShardDto` (stage enum, index, count); an invalid value is refused in the builder, as a
  contradictory diff mode is today. Unset is exactly today's behaviour: the argv and the code path are unchanged
  (pinned by a test).
- It is a lane setting, not a pass-through tool flag: `qa` still takes a tool and a path, and the shard argv is the
  lane's own (`InfectionArguments::shard()`), not forwarded text. Owner to confirm this reading (open question 1).
- A shard requires `infectionDiffMode` Full (the workflow sets `infectionDiffBase=full`, which exists); anything else
  is refused with a `LogicException`, as `InfectionOptionsDto` already does for a mode and base that contradict.

**Per shard (`<i>/<n>`):**

1. `runConfig()` as today (derived `infection.json`, ignored paths, absolute paths, JSON log target).
2. Skip diff resolution, and skip `checkPreloadedSource()` (the coverage stage did the whole-tree check once; a subset
   check per shard adds nothing and costs a probe load).
3. Read the coverage manifest and `index.xml`, plan, take shard i's files.
4. Argv from `InfectionArguments::shard()`: the common part (`--coverage`, `--skip-initial-tests`, `--threads`,
   `--configuration`) plus `--filter=<comma list>` plus `--log-verbosity=all`. **No `--min-msi`, no
   `--min-covered-msi`, no `--with-uncovered`**: a shard's local score is not the verdict, and the full run mutates
   covered code only. The mutant timeout is in `infection.json`, untouched.
5. Write `shard.json` next to the log (index, count, coverage hash, hash of the file list, file count), run the phar
   as today (low priority, no Xdebug), then check only that the JSON log exists and parses with a `killed` list and a
   `processOutput` per killed mutant.
6. **Do not run the vacuous-kill judgement in a shard.** `VacuousKillDetector` decides "the suite never started" from
   the share of vacuous kills among all kills (`suiteNeverStarted()`: every judged kill is vacuous). On a shard, a few
   files whose mutants break the test bootstrap could make every kill in that shard vacuous and crash it, while the
   serial run sees a mix and goes on to the floor rule (or the reverse). The judgement runs once on the merged log
   (section 4), where it equals serial.
7. Result `passed` when Infection ran and wrote a readable log, `crashed` otherwise (a non-zero exit, a missing or
   unreadable log). Without floors Infection exits 0 on any score.

Shard and join stages do not need Xdebug (no coverage is generated), but the lane today prints "SKIPPING" and exits
clean when Xdebug is off. Both stages are exempt from that skip; and a skipped shard cannot slip through anyway,
because the join requires every shard's log.

**Diff vs full scope.** Sharding applies only when the lane's scope resolution says "full run" (a configuration change
or no merge base). Diff runs (few files, the diff floor, `--with-uncovered`, positional paths) are never sharded and
their logic is untouched. To keep one decision, the "is it a full run?" part of `resolveDiffScope()` moves out of the
710-line `InfectionTool` into a small class (`InfectionRunScope`) returning Full(reason) | Diff(paths) | Skip(reason);
the lane and the planner (section 5) both call it, so the workflow cannot disagree with the lane.

## 4. Aggregate verdict

Stage `join/<n>`, run by the gate job after downloading all shard artifacts into `var/qa/infection-shards/<i>/`.

1. **Completeness.** Exactly `n` directories each with a readable log and `shard.json`; indices `1..n` once each; all
   `shard.json` agree on count, coverage hash and file-list hash, and the coverage hash belongs to the commit under
   test. Any gap crashes the lane naming the missing or mismatched shard. A rerun shard from another commit cannot be
   mixed in.
2. **Merge** (`InfectionLogMerger`, pure): sum the `stats` counts (`totalMutantsCount`, `killedCount`,
   `killedByStaticAnalysisCount`, `notCoveredCount`, `escapedCount`, `errorCount`, `syntaxErrorCount`, `skippedCount`,
   `ignoredCount`, `timeOutCount`); concatenate every mutant list (`killed`, `escaped`, `timeouted`, `notCovered`,
   `errored`, `syntaxErrors`, `skipped`, `ignored`) in shard order; recompute `msi`, `mutationCodeCoverage` and
   `coveredCodeMsi` from the summed counts with the formulas `KillJudgementDto` and `VacuousKillDetector::withCounts()`
   already replicate (tested = total - skipped - ignored; covered = tested - notCovered; detected includes timeouts
   unless the config counts them as escaped; two decimals, half away from zero). The timeout rule is one config, so
   the same in every shard; a shard whose stats do not reproduce its own `msi` crashes, as `withCounts()` already
   does. The merged log is written to the normal JSON log path, so the artifact upload and every later reader see a
   log shaped like a serial one.
3. **Judge** the merged log with the lane's existing `judgeKills()` (vacuous kills, mixed-kill floor rule) unchanged,
   except that its `ProcessResultDto` is replaced by the floor outcome of step 4.
4. **Floors** (`InfectionFloorJudge`, new, pure): MSI >= `minMsi` and covered MSI >= `minCoveredMsi`, the same
   configured numbers, compared the way Infection compares (open question 4: whether on the rounded or unrounded
   score; the equivalence test pins what is found). A full run whose shards together hold zero mutants is refused as
   suspicious, as the lane refuses a pass with no log today.
5. Print the escaped mutants of all shards, the combined counts and both scores, then `passed` or `failed` with the
   lane's existing identifier. **Equivalence argument**: same mutant set (per-file independence), same per-mutant
   verdict, same counts by addition, same formulas, the same judge code on the same data. The Phase 3 proof compares
   merged counts, escaped list and verdict with a serial run on one commit.

**A shard crashes.** Its job is red and its log is absent; upload uses `if: always()`. The gate then fails twice over:
the `needs` result check and the completeness check. No partial verdict is ever a pass. A crashed shard is rerun with
"re-run failed jobs"; the gate reruns and re-joins; the plan is deterministic and the hashes are checked, so the rerun
slots in.

## 5. Workflow and template

Changes identical in `.github/workflows/qa.yml` and `templates/github-actions/php-qa-ci.yml` (they stay identical, as
today).

- **`scope` job** (short limit): checkout (full history), PHP, deps, then a thin `bin/infection-plan` entry (PHP in
  `src/`; the entry only wires arguments) printing `sharded=true|false` and `shards=N`, redirected into
  `$GITHUB_OUTPUT` by one `run:` line. It uses `InfectionRunScope`, `useInfection`, and the shard count from the
  repository variable `PHP_QA_CI_INFECTION_SHARDS` (unset or 1 means off, today's behaviour; consumers opt in). It
  prints `sharded=false` for push runs (`useInfection=0` today) and for a `workflow_dispatch` of a single tool.
- **`pipeline` job** (the current `qa` job body, 45 minute limit): same steps, with `useInfection` forced `0` when
  `sharded=true`. Not itself the required status.
- **`coverage` job** (`needs: scope`, `if: sharded`): `qa -t infection` with `infectionShard=coverage`; uploads the
  handoff artifact.
- **`mutate` matrix job** (`needs: coverage`, matrix `shard`, `fail-fast: false` so one red shard does not hide the
  others' escaped lists): download the artifact, `qa -t infection` with `infectionShard=<i>/N` and
  `infectionDiffBase=full`; upload `var/qa/infection/` as `infection-shard-<i>` with `if: always()`. The matrix list
  comes from `fromJSON` of the planner's output, so N lives only in the repository variable.
- **Gate job `qa`, `name: QA Pipeline`** (the required status; `needs: [scope, pipeline, coverage, mutate]`,
  `if: always()`): fails when any needed result is `failure` or `cancelled`, or `scope` did not succeed (declarative
  `if:` steps, no Bash logic). When `sharded`, it checks out, installs, downloads the shard artifacts and runs
  `qa -t infection` with `infectionShard=join/N`. Branch protection matches the check name, not the job id, so the
  required name stays one stable string. The gate is `always()` because a job skipped through a failed `needs` reports
  as skipped, which GitHub treats as passing.
- Not sharded: the gate only does the result check; `coverage` and `mutate` are skipped. Today's path costs one extra
  short job.
- Every new job carries its own `timeout-minutes`, none above the existing 45. Artifacts: handoff retention 1 day,
  shard logs 7 days (as `test-results`).
- Cost: wall time becomes the slowest of the `pipeline` job and (scope, coverage, one shard, join). CI compute grows
  by about N times the per-job setup plus one duplicated coverage run (the `pipeline` job still runs its own phpunit).
  `concurrency` (cancel in progress per pull request) already covers the new jobs.

## 6. Tests, first (red)

Existing style: Small tests under `tests/Small/`, Large for process-level ones.

1. **Settings**: grammar of `infectionShard` (valid, `0/3`, `4/3`, `1/0`, `join/0`, garbage, trailing text); env and
   builder agree; a shard with a mode other than Full is refused; unset leaves `InfectionArguments::full()` and the
   lane's run unchanged (guards "local behaviour as today").
2. **Weights and balancer**: `index.xml` fixture to weights (zero executed, missing totals); balancer property tests
   (every file in exactly one shard; union equals input; deterministic under input reorder; makespan \<= average plus
   largest item; N above the file count leaves empty shards; N = 1 returns all; equal weights break by path then
   index).
3. **Argv**: `InfectionArguments::shard()` equals common + filter + verbosity, and contains none of `--min-msi`,
   `--min-covered-msi`, `--with-uncovered`, `--only-covering-test-cases`; no timeout argument is ever added.
4. **Lane, with the existing fakes** (`IncludedFilesProbeInterface`, process fakes): a shard does not generate
   coverage (missing coverage crashes with the named message), does not call the preload check, does not judge
   vacuous kills (an all-vacuous shard passes), crashes on SHA, root or hash mismatch, writes `shard.json`. The
   coverage stage runs `ensureCoverage` and the whole-tree preload check and emits the manifest.
5. **Merger and judge**: the core property test: take a fixture log, split its mutants randomly into k logs with
   consistent stats, merge, and require the merged judgement (counts, both scores, vacuous list, verdict) to equal that
   of the unsplit log, over many seeds and k. Boundary tests: score exactly at the floor, one hundredth below, a
   rounding half-up case, zero killed, all vacuous in one shard but mixed in total (step 6 of section 3), timeouts
   counted as detected and as escaped.
6. **Completeness**: missing shard, duplicate index, wrong count, differing coverage hash, log from another SHA,
   unreadable log, stats that do not reproduce their `msi`: each a crash naming the shard.
7. **Planner**: `InfectionRunScope` returns Full for each `InfectionFullRunTriggers` path and for no merge base, Diff
   otherwise; the planner's output equals the lane's decision for the same fixtures (one shared class, one table of
   cases).
8. **Workflow files** (extend the existing template tests): `qa.yml` equals the template; exactly one job named
   `QA Pipeline`; it has `if: always()` and lists every other job in `needs`; `fail-fast: false`; every job has
   `timeout-minutes`; upload steps use `if: always()`; `coverage` and `mutate` are gated on `sharded`.
9. **Large**: end to end on a tiny fixture project with the real phar: a serial full run against coverage + 3 shards +
   join, with the same counts, escaped list and verdict.

**Infection strength of the new `src/` files.** Mutation-prone spots, so write the boundary tests before the code:
balancer comparisons and tie-break (`<` against `<=` changes which shard wins a tie), 1-based index off-by-one, parse
anchors (`^...$`), the rounding mode and precision in the score recompute, `>=` against `>` at a floor, the summation
loops (a mutated `+=`), and array merge order. The repeated-string-literal rule applies to the log keys
(`killedCount`, `processOutput`, ...): they become class constants shared with `VacuousKillDetector` (its `COUNTS`
list moves to one place) rather than copied. New classes are `final readonly`, `@internal`, docblock arrays
`list<T>` / `array<K, V>`, no `==`, no `empty()`. The new failure texts reuse `phpqaci.infection`; no new identifier is
needed (open question 6).

## 7. Risks and open questions

Risks:

- The weight proxy is unproven (Task 2.0b measures it; the cached real-count refinement is the escape hatch).
- Infection's `--filter` with about 300 absolute paths: measured with two paths only; the argv length (about 20 KB) is
  far under limits, but the accepted form (absolute or project relative) needs a test.
- Coverage path validity across jobs: assumed identical workspace path; checked at runtime (section 2).
- Timeouts count as detected and are load sensitive (MEASUREMENT D, F); separate machines avoid the same-host
  contention that invalidated one run, but a loaded runner can still produce flaky timeouts, as a serial run can.
- Hosted runners are smaller than the measurement host; real per-shard time is unknown until Task 2.0a.
- One more moving part in the required path; mitigated by the single gate and its completeness checks.

Open questions for the Owner:

1. Is a lane setting `infectionShard` (stage, index, count; the lane builds its own argv) consistent with "not a tool
   proxy"? The design says yes: no Infection flag is exposed.
2. Consumer default: the shipped template off unless `PHP_QA_CI_INFECTION_SHARDS` is set (proposed), or on?
3. Shard count and CI compute budget for this repository (6 to 8 proposed; above about 9 the biggest file is the
   floor).
4. How Infection compares a score to its floor (rounded or not); the equivalence test pins what is found.
5. Is a gate job with a different job id acceptable for the repository's branch protection, given the check name stays
   `QA Pipeline`? (Needs a look at the required-check configuration.)
6. Should the join's failure modes reuse the existing identifier, or get a page for "shard handoff broken"?
7. None on the changed-lines question: this design does not depend on it.

## 8. Phased task list for PLAN.md

### Phase 2.0 (prove assumptions, before code)

- [ ] **Task 2.0a**: On a scratch branch with a long job limit, run the serial full lane once on a hosted runner;
  record total time, per-file mutant counts and the log. This is the Phase 3 baseline too.
- [ ] **Task 2.0b**: From that log and the coverage `index.xml`, compute the correlation of executed lines with
  mutants per file; decide proxy against cached real counts; choose N.
- [ ] **Task 2.0c**: Check `--filter` with an absolute list on a full coverage dir, Infection's floor comparison, and
  that a downloaded coverage artifact works at the same path in a second job.

### Phase 2 (build, each step red first)

- [ ] **Task 2.1**: `InfectionShardDto`, parsing, builder and environment setting, settings row in
  `docs/configuration.md`; tests 1.
- [ ] **Task 2.2**: `InfectionRunScope` extracted from `InfectionTool` with no behaviour change (existing tests stay
  green); tests 7.
- [ ] **Task 2.3**: weights, balancer, `InfectionArguments::shard()`; tests 2 and 3.
- [ ] **Task 2.4**: lane stages `coverage` and `<i>/<n>`, manifest, `shard.json`; tests 4.
- [ ] **Task 2.5**: log merger, floor judge, completeness, `join` stage; tests 5 and 6.
- [ ] **Task 2.6**: `bin/infection-plan` entry; workflow and template changes; tests 8.
- [ ] **Task 2.7**: Large end to end (9); `docs/tools/infection.md`; `## Unreleased` entry (consumer-visible: a new
  setting, a new entry point, a template change).

### Phase 3 (prove)

- [ ] **Task 3.1**: A pull request touching `qaConfig/infection.json`: the required check finishes; the aggregate
  matches the 2.0a serial baseline (counts, escaped list, verdict). Rerun one shard to show re-join works; fail one
  shard on purpose to show the gate goes red.
- [ ] **Task 3.2** (optional): cached real-count weights if 2.0b says the proxy is poor.
