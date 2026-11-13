# Design: sharding the full Infection run

Design only (Plan 00026, Task 1.1 and 1.2). Nothing here is built. Inputs: [PLAN.md](./PLAN.md),
[MEASUREMENT.md](./MEASUREMENT.md), issue #344 (option 3, "shard the full run"; same root as #303), the Infection lane
(`InfectionTool`, `Infection/*`), `.github/workflows/qa.yml` and `templates/github-actions/php-qa-ci.yml` (identical
today; the single job `QA Pipeline` has the 45 minute `timeout-minutes` limit).

Revised after the independent review recorded in
[subagent-reports/261030-design-review-fresh-reviewer.md](./subagent-reports/261030-design-review-fresh-reviewer.md);
the revision is described in [subagent-reports/261030-design-revision-builder.md](./subagent-reports/261030-design-revision-builder.md).
Revised again after the second independent review,
[subagent-reports/261030-design-review-second-fresh-reviewer.md](./subagent-reports/261030-design-review-second-fresh-reviewer.md);
that revision is described in [subagent-reports/261030-design-revision-3-builder.md](./subagent-reports/261030-design-revision-3-builder.md).

## 0. Shape in one picture

```text
scope ──(full run?)──► coverage ──artifacts──► shard 1..N ──artifacts──► QA Pipeline (gate: join + verdict)
  └────────────────► pipeline (all other lanes, useInfection=0 when sharded) ──────────────┘
```

- `scope`: one cheap PHP step that answers "would the Infection lane be a full run?" with the lane's own logic.
- `coverage`: runs the lane's own coverage generation once and uploads it.
- `shard i/N`: the lane, told to mutate only shard i's files from the downloaded coverage, judging nothing.
- gate job, named `QA Pipeline` (the one required status): joins the shard logs, judges them against the unchanged
  floors, and fails if any needed job failed or is missing.
- When the lane is not a full run, or sharding is off, the run is today's single job; the matrix jobs are skipped.

## 0a. What Infection 0.35.8 does (settled from its source)

Read statically from the 0.35.8 tag (no PHP binary was available to run it). Paths are Infection's `src/` tree.

- `--filter` is a substring match, not an exact one. `Source/Collector/BasicSourceCollector.php:159-168` wraps the
  collected `*.php` files in `RealPathFilterIterator` with the filter values; `FileSystem/Finder/Iterator/RealPathFilterIterator.php:78-83`
  (`toRegex()`) turns a value into `'/' . preg_quote($value, '/') . '/'` unless it already is a regular expression, and
  `accept()` (:53-61) tests that against the file's `getRealPath()`. A value that matches no file is dropped without a
  word: other files still match, so there is no `NoSourceFound` and no empty log (section 4, step 1b). So a value matches every file whose real path contains it. An
  absolute path from the project root cannot be contained in the real path of a different file under the same root
  (two distinct paths ending in `.php` cannot be substrings of one another when both start with the same root), so the
  absolute list is safe; a project-relative or partial value is not (`src/A.php` also matches `vendor/x/src/A.php` if
  that is in scope). The design therefore passes absolute paths and the join verifies membership anyway (section 4).
- Several values: `Configuration/SourceFilter/PlainFilter.php:80-88` splits on `,`, trims each part and drops blank
  parts (`array_filter` also drops a part that is the string `0`); `toString()` joins them back (:72-75). Multiple
  values act as alternatives; that is the behaviour of Symfony's `MultiplePcreFilterIterator`, which lives outside the
  fetched tree and is still to be proven (Task 2.0c), though MEASUREMENT F already ran two paths.
- `--filter` is deprecated: `Command/Option/SourceFilterOptions.php:131-137` prints a deprecation warning since 0.34.0
  and tells callers to pass positional paths. Positional paths are wrapped in `PositionalPathsFilter`
  (`Configuration/SourceFilter/PositionalPathsFilter.php:38-53`), which the configuration factory resolves into a
  `PlainFilter` for source paths, so the matching is the same. The lane's diff flavour already passes source files as
  positional arguments (MEASUREMENT setup), so the shard argv passes its files positionally too: same matching, no
  warning in a log. Task 2.0c proves the two forms select the same files.
- Interaction: `--filter` with `--git-diff-filter` / `--git-diff-lines` is refused (an `Assert`,
  `SourceFilterOptions.php:251-260`), and so is a positional path together with either git option (:270-277) or with
  `--filter` (:262-268). A shard uses none of the git options, which is also why it must not set them.
  `--only-covering-test-cases` is a separate test-selection option, orthogonal to the source filter; a shard does not
  pass it (it loses real kills, MEASUREMENT C).
- Zero files matched: `BasicSourceCollector::collect()` (:67-76) throws `NoSourceFound::noSourceFileFound` when the
  filter leaves no file; the exception is flagged filtered when a filter was given (`Source/Exception/NoSourceFound.php:119-125`, `noSourceFileFound()`: `isSourceFiltered: $filter !== null`), and
  `Command/RunCommand.php:398-402` turns a filtered `NoSourceFound` into a success message and exit success. The
  exception leaves `Engine::execute()` (`Engine.php:93-115`) at `runMutationAnalysis()` (:100), outside the `try` whose
  `finally` dispatches `ApplicationExecutionWasFinished`, so a JSON log is probably not written. A run that matches no
  file therefore exits 0 and (by inference) writes no log; the shard must never depend on that (section 3).
- Files matched but no mutants: `Mutation/FileMutationGenerator.php:88-90` returns nothing for a file with no covering
  tests under the covered-only mode, silently. Whether the run then writes a zero-count JSON log and exits 0 is not
  readable from the files fetched and is unproven (Task 2.0c).
- Exit code and floors: `Engine.php:102-111` runs the timeout and minimum-MSI checks only. **Floors do not come only
  from the command line**: `Configuration/ConfigurationFactory.php:386-394` is `$minMsi ?? $schema->minMsi` and
  `$minCoveredMsi ?? $schema->minCoveredMsi`, `:401-404` is `$maxTimeouts ?? $schema->maxTimeouts`, and `:379-384` is
  `ignoreMsiWithNoMutations` likewise, so a floor in the `infection.json` the lane resolves (its own, or the project's)
  is applied when the command line omits it. A shard exits success on any score only when its derived configuration
  carries none of them (section 3, step 1).
- How a score meets a floor (settled, was open question 4): `Metrics/MinMsiChecker.php:123-131` is
  `$this->minMsi > 0 && $msi < $this->minMsi` (and the same for the covered score), so the comparison is strict
  less-than, a floor of 0 disables that check, and when both floors are 0 the whole check is skipped (:64-68, :116-121).
  The score compared is the one `Metrics/Calculator.php` produced, already rounded: `round($value, $roundingPrecision,
  PHP_ROUND_HALF_UP)` at :145-148, with the precision a constructor argument (:53), so the precision is configuration,
  not a constant two. The join's floor judge uses exactly this comparison on the recomputed, rounded score.

## 1. Shard unit, weight and balancing

**Unit: one source file.** Evidence: Infection generates mutants per file, independent of other files, so the union of
per-file runs is the serial mutant set; `--filter=a.php,b.php` mutated exactly those files (MEASUREMENT F). Infection's
CLI cannot split inside a file. The largest file holds 2,009 of 19,590 mutants (10.3 percent), so with N up to about 8
no single file exceeds the average shard and file granularity balances well. Above about 9 shards the largest file
becomes the floor of the slowest shard, so the design caps N (open question 3). The cap is for the measurement host's
speed; hosted-runner sizing is **unproven**: a hosted runner has fewer CPUs, so the N it needs could pass 9. Task 2.0a
measures it, and if it says N must exceed the file-granularity ceiling the design does not hold as written: the options
are then a smaller mutation scope per job, a faster runner class, or a within-file split (not available from Infection's
CLI), each an Owner decision. Nothing here assumes the measurement will fit.

**Weight: covered executable lines from the coverage XML, computed free in every shard.** The candidates:

- A whole-tree dry run costs 661 s (MEASUREMENT): rejected.
- A committed weights file rots, churns on every source change, conflicts between branches, and needs a changelog or
  trailer on each refresh: rejected.
- Source size (bytes or lines) counts comments, docblocks and uncovered code, none of which produce mutants (the full
  run mutates covered code only).
- `coverage-xml/index.xml` already lists, for every file, `<totals><lines executable=".." executed=".."/>`. Every
  shard has it after the handoff, parsing is cheap, and it measures exactly "covered code", the only code a full run
  mutates. Weight = `max(1, executed)`.

**Only files inside the mutation scope are planned.** `index.xml` lists every file PHPUnit covered, including files
the derived Infection configuration removes (`source.excludes` of the shipped `infection.json`, and the lane's ignored
paths added by `IgnoredPathsInfectionConfig`). The planner intersects the index with the source scope the lane hands
Infection (`source.directories` minus the excludes and ignored paths), so excluded files neither inflate weights nor
fill shards, and the file list given to Infection holds only files Infection will mutate. The planner records the
files it dropped for being out of scope (count only, in `shard.json`).

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
runner). N is chosen so a shard's mutation time sits well inside the job limit; its value for this repository is fixed
by the real baseline of Task 2.0a and kept in one place (section 5). N is also refused when it exceeds the number of
planned files (section 3, empty shards).

## 2. Coverage: generated once, handed to shards

- Stage `coverage` runs the lane's existing `ensureCoverage()` (the same phpunit invocation, config and Xdebug mode as
  a serial run, so the coverage is identical) plus `checkPreloadedSource()` over the whole source, then ends with
  `passed`. A red suite fails here exactly as in a serial run.
- Uploaded as two artifacts (retention 1 day), both named so they cannot collide with the existing `coverage` artifact
  that the `coverage-report` job reads:
  - `infection-coverage-handoff`: `var/qa/phpunit_logs/coverage-xml/` and `var/qa/phpunit_logs/phpunit.junit.xml` (the
    lane hands Infection `--coverage=<logsDir>`, so Infection reads these). Downloaded by shards only.
  - `infection-plan-input`: small; `index.xml` and `coverage-manifest.json` written by the stage (commit SHA, project
    root, hash of `index.xml`, hash of the junit file). Downloaded by shards and by the gate, so the gate can recompute
    the plan without the large handoff.
    The existing `coverage` artifact is untouched; the `coverage-report` job keeps reading it, and its `needs` moves
    from the id `qa` to `pipeline`, the job that uploads `coverage` (section 5, gate bullet). The new stage job is named `infection-coverage`, not `coverage`.
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

1. `runConfig()` as today (derived `infection.json`, ignored paths, absolute paths, JSON log target), **plus a strip**:
   for the `coverage` and `<i>/<n>` stages the derived configuration (the copy `runConfig()` already writes; the
   original is never edited) has the top-level keys `minMsi`, `minCoveredMsi`, `maxTimeouts` and
   `ignoreMsiWithNoMutations` removed, because Infection applies them from the file when the command line omits them
   (section 0a). The strip is a small pure step on the decoded array (`unset` of those four keys before the JSON is
   written), so a project whose `infection.json` sets floors cannot make a shard judge its partial score. The floors
   the join judges against are read from the **original** resolved configuration (same file the serial run reads, and
   the same precedence: a lane setting over the file), never from a shard's derived copy. Pinned by a test that feeds a
   configuration carrying all four keys and requires the written shard configuration to hold none of them, that the
   untouched keys (`timeout`, `source`, `tmpDir`, `timeoutsAsEscaped`, logs) survive, that a serial run's derived
   configuration is unchanged, and that the join reads the original values (Task 2.1).
2. Skip diff resolution, and skip `checkPreloadedSource()` (the coverage stage did the whole-tree check once; a subset
   check per shard adds nothing and costs a probe load).
3. Read the coverage manifest and `index.xml`, plan (in-scope files only, section 1), take shard i's files. N larger
   than the number of planned files is a configuration error, not a quiet empty shard; it cannot be known before
   `index.xml` exists, which is only after the coverage job. So the **refusal is made in the `coverage` stage** (the
   lane stage that writes the manifest plans once and fails there, before any shard job starts); each shard repeats
   the check as a guard. Files with executed lines but no mutants are not covered by that refusal (step 5).
   Before running, the shard checks each of its paths: it must equal `realpath()` of itself, be a readable `.php` file,
   and be inside the mutation scope the lane hands Infection; any difference is a crash naming the file. This removes
   the known causes of Infection silently dropping a path (a symlinked root, a path that is not a real path, a
   normalisation difference), because the match is the pattern of the real path against the real path.
4. Argv from `InfectionArguments::shard()`: the common part (`--coverage`, `--skip-initial-tests`, `--threads`,
   `--configuration`) plus the shard's absolute file paths as positional source arguments (section 0a: the same
   matching as `--filter`, without its deprecation warning, and as the diff flavour already passes them) plus
   `--log-verbosity=all`. **No `--min-msi`, no `--min-covered-msi`, no `--with-uncovered`, no git option, no
   `--filter`** (the git options are refused together with a path, section 0a): a shard's local score is not the
   verdict, and the full run mutates covered code only. The mutant timeout is in `infection.json`, untouched. The
   absence of the floors on the command line is not enough on its own: step 1 strips them from the configuration.
5. **`shard.json` location and ordering.** The lane empties `var/qa/infection/` and deletes the JSON log immediately
   before the phar (`InfectionTool` does so, and the project may name `logs.json` elsewhere), so a record written
   there beforehand is destroyed. `shard.json` is therefore written **after that emptying step**, to a directory the
   lane never empties, `var/qa/infection-shard/`, and the upload step names it there. It is written twice: before the
   phar with `status: "running"` (index, count, coverage hash, the shard's full file list, file count, the count of
   out-of-scope files dropped), and again after the phar with `status: "finished"`, the phar's exit status, the
   resolved path of the JSON log it read, and the per-file evidence of section 4, step 1b. The join treats a `running`
   record, a non-zero recorded exit status or a record without a readable log as a crash, so a crashed shard whose
   artifact survived (kept by `overwrite: true` when the rerun fails before uploading) cannot look complete.
   Then run the phar as today (low priority, no Xdebug), then check that the JSON log exists and parses with its
   `stats` and the status lists of section 4, step 2.
   **Empty shard** (a planned file list that is empty; the coverage-stage refusal makes it a bypass): writes
   `shard.json` with `empty: true` and a synthetic all-zero log itself, and never starts Infection (an unmatched
   filter makes Infection exit 0 with, by inference, no log, section 0a). A non-empty shard that ends with no readable
   log is always a crash. A non-empty shard whose files yield no mutants is the separate case of step 1b of section 4
   (a file with executed lines can have none), and Task 2.0c (4) settles what Infection writes then.
6. **Do not run the vacuous-kill judgement in a shard.** `VacuousKillDetector` decides "the suite never started" from
   the share of vacuous kills among all kills (`suiteNeverStarted()`: every judged kill is vacuous). On a shard, a few
   files whose mutants break the test bootstrap could make every kill in that shard vacuous and crash it, while the
   serial run sees a mix and goes on to the floor rule (or the reverse). The judgement runs once on the merged log
   (section 4), where it equals serial.
7. Result `passed` when Infection ran, exited 0 and wrote a readable log, `crashed` otherwise (a non-zero exit, a
   missing or unreadable log). With the floors stripped (step 1) Infection exits 0 on any score, so a non-zero exit
   is a real fault, not a verdict.

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

0. **Inputs.** The gate downloads `infection-plan-input` (`index.xml`, manifest) and every shard artifact; it does not
   download the large coverage handoff. It then **recomputes the plan itself** (same planner, same in-scope source
   set, same N, same `index.xml`), so the expected file list of every shard is known independently of what the shards
   report. The stage empties only `var/qa/infection/` (the merged output) and never `var/qa/infection-shards/`
   (the downloaded inputs); the order is: empty the output directory, then read the shards.
1. **Completeness and plan agreement.** Exactly `n` directories each with a readable log and `shard.json`; indices
   `1..n` once each; every `shard.json` agrees on count and coverage hash, the manifest's commit is the commit under
   test, and the manifest's `index.xml` hash equals the downloaded file's. Each `shard.json` file list equals the
   recomputed plan's list for that index, exactly (a missing, extra or moved file fails, naming the shard and file),
   and the union of the lists equals the recomputed planned set with no file in two shards. Any gap crashes the lane
   naming the missing or mismatched shard. A rerun shard from another commit cannot be mixed in.
   1a. **Mutant membership.** For every mutant in every shard's log, the source file it names (`originalFilePath`) must be
   in that shard's recorded list, and no mutant identity (`originalFilePath`, `originalStartLine`, `mutatorName`,
   `originalSourceCode`, `mutatedSourceCode`; the entry carries no line range) may appear in two shards. Either breach
   fails the join loudly, naming the shard, the file and the mutant. This does not rely on the file-selection semantic
   being exact: a substring match that pulled a foreign file into one shard is caught here instead of double-counted.
   1b. **Per-file evidence that Infection mutated each planned file.** 1a checks only mutants that are present, so it
   cannot see a planned file Infection never selected: an unmatched value is dropped silently (section 0a) while the
   shard's other files still match, so there is no `NoSourceFound`, no empty log and no crash, and the serial run has
   no such failure mode. The join therefore requires, for **every planned file with executed lines**, one of:
   (a) the file appears in the shard's log (any status list, by `originalFilePath`); or (b) the file is named in the
   shard's `shard.json` as a zero-mutant file *and* carries evidence from Infection that it was processed. The shard
   derives a per-file mutant count from its own log (all status lists), writes it into `shard.json` (`perFile`), and
   lists the planned files with count 0 under `zeroMutant`; the join checks that `perFile` equals the count recomputed
   from the log, that the keys equal the recomputed plan's list for the shard (so a path cannot vanish from both),
   that `sum(perFile)` equals the total of the log's mutant entries, and that every `zeroMutant` file is accepted by
   the evidence rule below. A planned file with executed lines that is in neither (a) nor an accepted (b) fails the
   join naming the shard and the file: that is how "a path that matched nothing" becomes a crash. Together with the
   shard's pre-run `realpath()` check (section 3, step 3), selection is made correct by construction and then proved
   afterwards. *Evidence rule for (b)*: whether Infection leaves any per-file trace of a processed file that yielded no
   mutants (a line in its text log at `--log-verbosity=all`, a debug log, or the zero-count log of Task 2.0c (4)) is
   **not yet known**. Until Task 2.0c (8) finds one, (b) accepts nothing and a zero-mutant planned file fails the join
   (fail closed); Task 2.0a records how many in-scope covered files of this repository have no mutants in the serial
   log, which says whether that is acceptable here or a trace must be found first. If the number is zero the rule
   costs nothing.
2. **Merge** (`InfectionLogMerger`, pure): sum every integer count under `stats` that is a mutant count
   (`totalMutantsCount`, `killedCount`, `killedByStaticAnalysisCount`, `notCoveredCount`, `escapedCount`,
   `errorCount`, `syntaxErrorCount`, `skippedCount`, `ignoredCount`, `timeOutCount`); concatenate every array-valued
   top-level key other than `stats` (in 0.35.8, `Reporter/JsonReporter.php:78-85` emits `escaped`, `timeouted`,
   `killed`, `killedByStaticAnalysis`, `errored`, `syntaxErrors`, `uncovered` and `ignored`; there is no `notCovered` or
   `skipped` list, and `uncovered` is always empty in covered-only mode) in shard order. Each entry is
   `{mutator: {mutatorName, originalSourceCode, mutatedSourceCode, originalFilePath, originalStartLine}, diff,
   processOutput}`. **The merger does not rely on that hand-written list silently**: it iterates
   the keys actually present, and a top-level key or `stats` key it cannot classify as a sum, a recomputed score or
   a concatenated list fails the join by name, so a list a newer or different Infection emits is never dropped from the
   merged log while the counts still add up. The key set is pinned by a fixture taken from a real 0.35.8 log
   (Task 2.0c). `VacuousKillDetector::COUNTS` (which excludes `escapedCount`) and the merger's sum list stay separate
   constants. Then recompute `msi`, `mutationCodeCoverage` and
   `coveredCodeMsi` from the summed counts with the formulas `KillJudgementDto` and `VacuousKillDetector::withCounts()`
   already replicate (tested = total - skipped - ignored; covered = tested - notCovered; detected includes timeouts
   unless the config counts them as escaped; rounded half up to the precision the configuration gives,
   `Metrics/Calculator.php:145-148`). `timeoutsAsEscaped` and the rounding precision are read from the same resolved
   configuration the shards ran with, not assumed. The timeout rule is one config, so
   the same in every shard; a shard whose stats do not reproduce its own `msi` crashes, as `withCounts()` already
   does. The merged log is written to the normal JSON log path, so the artifact upload and every later reader see a
   log shaped like a serial one.
3. **Judge** the merged log with the lane's existing `judgeKills()` (vacuous kills, mixed-kill floor rule) unchanged,
   except that its `ProcessResultDto` is replaced by the floor outcome of step 4.
4. **Floors** (`InfectionFloorJudge`, new, pure): the same configured numbers a serial run would apply (lane setting
   over the original resolved `infection.json`, section 3 step 1), compared the way Infection compares them
   (`Metrics/MinMsiChecker.php:123-131`): on the already rounded score, `floor > 0 && score < floor` fails, strict, a
   floor of 0 off. `maxTimeouts`, when configured, is judged against the merged `timeOutCount` (a shard cannot judge
   it), and `ignoreMsiWithNoMutations` as `MinMsiChecker.php:76-82` does. A full run whose shards together hold zero
   mutants is refused as suspicious, as the lane refuses a pass with no log today.
5. Print the escaped mutants of all shards, the combined counts and both scores, then `passed` or `failed` with the
   lane's existing identifier. **Equivalence argument**: same mutant set (per-file independence), same per-mutant
   verdict, same counts by addition, same formulas, the same judge code on the same data. The Phase 3 proof compares
   merged counts, escaped list and verdict with a serial run on one commit, under this tolerance rule. **Must be equal**:
   the mutant set (total, and the multiset of identities), `notCoveredCount`, `escapedCount` and the escaped list,
   `errorCount`, `syntaxErrorCount`, `skippedCount`, `ignoredCount`, and the pass/fail verdict. **May differ**: how a
   mutant that timed out is classed. Timeouts count as detected (MEASUREMENT D, F) and move with machine load, so
   `timeOutCount` and the `timeouted` list may differ between runs, and `killedCount` and the scores with them. The
   rule: the multiset of identities of (killed + killedByStaticAnalysis + timeouted) must be equal, every identity that
   differs in class moves only between `killed` and `timeouted`, and the verdict must be equal; an identity that moves
   into or out of `escaped` is a failure, not noise, because it changes the verdict-relevant set. Scores that differ by
   more than the share of the moved timeouts are investigated, not accepted.

**A shard crashes.** Its job is red and its log may be absent; upload uses `if: always()`. The gate then fails twice
over: the `needs` result check and the completeness check. No partial verdict is ever a pass. A crashed shard is rerun
with "re-run failed jobs"; the gate reruns and re-joins; the plan is deterministic and recomputed, so the rerun slots
in.

**Rerun and artifact names.** A crashed shard that wrote a log has already uploaded its artifact in the run, and
`actions/upload-artifact` (v4 and later, as the template pins) rejects a second upload of the same name in one run
unless told to overwrite. The rule: shard artifacts are named `infection-shard-<i>` and every upload step sets
`overwrite: true`, so a rerun attempt replaces the crashed attempt's artifact and the join always sees the newest
upload per index. No attempt number is put in the name, so there is never a choice between two logs to make: the
gate, which itself runs only after the shard jobs of the final attempt finish, downloads exactly one artifact per
index. Because the join checks the commit, the coverage hash, the recomputed file lists and the recorded exit status, a stale artifact from
another commit or plan cannot pass. Task 3.1 proves this with a real rerun. The 1-day retention of the two coverage
artifacts means a rerun after a day fails at download; that is loud and accepted.

## 5. Workflow and template

Changes identical in `.github/workflows/qa.yml` and `templates/github-actions/php-qa-ci.yml` (they stay identical, as
today).

- **`scope` job** (short limit): checkout (full history), PHP, deps (`composer install`, so the entry point exists in
  the Composer bin dir), then `$(composer config bin-dir)/infection-plan` (section 5a) printing `sharded=true|false` and `shards=N`, redirected into `$GITHUB_OUTPUT` by one `run:` line. It uses
  `InfectionRunScope`, `useInfection`, and the shard count from the repository variable
  `PHP_QA_CI_INFECTION_SHARDS` (unset or 1 means off, today's behaviour; consumers opt in). It prints `sharded=false`
  for push runs (`useInfection=0` today) and for every `workflow_dispatch`: a manual run of the single tool
  `infection` stays unsharded, so it is still a serial run bound by the 45 minute limit; this is documented, not
  worked around.
- **`pipeline` job** (the current `qa` job body, 45 minute limit): same steps, with `useInfection` forced `0` when
  `sharded=true`. Not itself the required status. It `needs: scope` for that value, which puts the scope job's checkout,
  PHP setup and dependency install on the critical path of **every** run, sharded or not; this is a real serial
  cost, not "one short job". Alternative the build may take after measuring it: let `pipeline` decide `useInfection`
  itself by calling the same entry point in its own existing setup (no `needs`), at the price of computing the decision
  twice; the choice is recorded with Task 2.0a.
- **`infection-coverage` job** (`needs: scope`, `if: sharded`): `qa -t infection` with `infectionShard=coverage`;
  uploads `infection-coverage-handoff` and `infection-plan-input` (section 2). Its name differs from the existing
  `coverage` artifact and from the `coverage-report` job's inputs.
- **`mutate` matrix job** (`needs: infection-coverage`, matrix `shard`, `fail-fast: false` so one red shard does not
  hide the others' escaped lists): download both artifacts, `qa -t infection` with `infectionShard=<i>/N` and
  `infectionDiffBase=full`; upload as `infection-shard-<i>` with `if: always()` and `overwrite: true` (section 4,
  rerun rule). The upload names files explicitly: the JSON log, `var/qa/infection-shard/shard.json` and the text logs. It **excludes the
  Infection `tmp/` directory** (`tmpDir` in `qaConfig/infection.json`, large and of no use after the run). The matrix
  list comes from `fromJSON` of the planner's output, so N lives only in the repository variable. The job is gated on
  `sharded`, so the matrix expression is never evaluated with an empty list (a test asserts the guard).
- **Gate job `qa`, `name: QA Pipeline`** (the required status;
  `needs: [scope, pipeline, infection-coverage, mutate]`, `if: ${{ !cancelled() }}`, **not** `always()`): `always()`
  keeps running after a workflow is cancelled by a newer push (`cancel-in-progress`), checking out and joining for a
  superseded run; `!cancelled()` still runs when a needed job failed or was skipped, which is what the gate needs. It
  fails when any needed result is `failure` or `cancelled`, or `scope` did not succeed, and when `sharded` and
  `infection-coverage` or `mutate` did not succeed, each by an **explicit result check** (a step guarded by an `if:` on
  the `needs` results and ending in a `run: exit 1`; a job cannot fail through declarative `if:` steps alone, so that
  one `run:` line is the whole of the "logic", with the expression kept out of the script per the existing
  `noScriptOfAWorkflowOrTemplateHoldsAnExpression` rule). When `sharded`, it checks out,
  installs, downloads `infection-plan-input` and the shard artifacts into `var/qa/infection-shards/<i>/`, and runs
  `qa -t infection` with `infectionShard=join/N` (the gate downloads what it needs; it never downloads the large
  coverage handoff). The merged log goes to the normal `var/qa/infection/`, emptied before the shards are read
  (section 4, step 0). Branch protection matches the check name, not the job id, so the
  required name stays one stable string. The gate needs the explicit result checks because a job skipped through a failed `needs` reports
  as skipped, which GitHub treats as passing; and because the id `qa` moves from the pipeline body to the gate, the
  `coverage-report` job's `needs: qa` would silently change meaning: it is changed to `needs: pipeline` (the job that
  uploads `coverage`) in the same commit, deliberately, and the test pins it.
- Not sharded: the gate only does the result check; `infection-coverage` and `mutate` are skipped. Today's path costs
  the `scope` job in series ahead of `pipeline` plus a short gate job (see the `pipeline` bullet).
- Every new job carries its own `timeout-minutes`, none above the existing 45. Artifacts: handoff retention 1 day,
  shard logs 7 days (as `test-results`).
- Cost: wall time becomes the slowest of the `pipeline` job and (scope, coverage, one shard, join). CI compute grows
  by about N times the per-job setup plus one duplicated coverage run (the `pipeline` job still runs its own phpunit).
  `concurrency` (cancel in progress per pull request) already covers the new jobs.

## 5a. The planner entry, `bin/infection-plan`

Logic in a class under `src/` (a small `final readonly` `@internal` planner service); `bin/infection-plan` only builds
it and prints (the Bash-for-invocation, PHP-for-logic ruling).

- **Inputs**: the project root and the same environment the lane reads (`useInfection`, `infectionDiffBase`, the diff
  base resolver's inputs), the shard count from `PHP_QA_CI_INFECTION_SHARDS`, and the event name for the manual-run
  rule. The scope job has the same checkout depth and dependency install as the pipeline job, so the diff base
  resolves identically.
- **Outputs**: lines of `key=value` on stdout, nothing else: `sharded=true|false`, `shards=<N>` and, when sharded,
  `matrix=[1,...,N]`. Diagnostics go to stderr.
- **Decision**: it calls `InfectionRunScope`, which returns `Full(reason)`, `Diff(paths)` or `Skip(reason)` and also
  models the cases the lane would refuse (uncommitted changes under the strict check) or fail on. Only `Full` with a
  count above 1 prints `sharded=true`. `Diff`, `Skip`, a refusal, a push run, a manual run, `useInfection=0` and an
  unset or 1 count all print `sharded=false`, so a refused or skipped lane never becomes a sharded run; the lane then
  runs (and refuses) as it does today, inside the `pipeline` job. A malformed count exits non-zero (the scope job
  fails loudly).
- **Registration, two places.** (1) `composer.json` `bin` lists `bin/infection-plan`, which is what puts it in a
  consumer's Composer bin dir; without it the template step would fail for every consumer who sets
  `PHP_QA_CI_INFECTION_SHARDS`. It also needs the `!/bin/infection-plan` line in `.gitignore` and the executable bit
  in git, all pinned by the existing `tests/Small/ComposerBinManifestTest.php` (declared and shipped agree, not
  git-ignored, executable). (2) The entry-point list in `qaConfig/qa.php` (as a recent change did for another `bin/`
  entry), so the dead-code lane analyses it. It is not a `qa` tool.
- **How a consumer's workflow calls it**: after `composer install`, `$(composer config bin-dir)/infection-plan`
  (the same form the shipped release workflow uses for `changelog-release`), its stdout appended to `$GITHUB_OUTPUT`.
  Inside this repository the bin dir is `bin`, so the same line runs the file in place.
- The same planner class also produces the shard file lists (section 1), which is what the gate's recomputation uses.

## 6. Tests, first (red)

Existing style: Small tests under `tests/Small/`, Large for process-level ones.

1. **Settings**: grammar of `infectionShard` (valid, `0/3`, `4/3`, `1/0`, `join/0`, garbage, trailing text); env and
   builder agree; a shard with a mode other than Full is refused; unset leaves `InfectionArguments::full()` and the
   lane's run unchanged (guards "local behaviour as today").
2. **Weights and balancer**: `index.xml` fixture to weights (zero executed, missing totals); balancer property tests
   (every file in exactly one shard; union equals input; deterministic under input reorder; makespan \<= average plus
   largest item; N above the file count leaves empty shards; N = 1 returns all; equal weights break by path then
   index).
3. **Argv**: `InfectionArguments::shard()` equals common + positional absolute paths + verbosity, and contains none of
   `--min-msi`, `--min-covered-msi`, `--with-uncovered`, `--only-covering-test-cases`, `--filter`, any git option;
   no timeout argument is ever added.
4. **Lane, with the existing fakes** (`IncludedFilesProbeInterface`, process fakes): a shard does not generate
   coverage (missing coverage crashes with the named message), does not call the preload check, does not judge
   vacuous kills (an all-vacuous shard passes), crashes on SHA, root or hash mismatch, writes `shard.json`. The
   coverage stage runs `ensureCoverage` and the whole-tree preload check and emits the manifest.
5. **Merger and judge**: the core property test: take a fixture log, split its mutants randomly into k logs with
   consistent stats, merge, and require the merged judgement (counts, both scores, vacuous list, verdict) to equal that
   of the unsplit log, over many seeds and k. Boundary tests: score exactly at the floor, one hundredth below, a
   rounding half-up case, zero killed, all vacuous in one shard but mixed in total (step 6 of section 3), timeouts
   counted as detected and as escaped.
6. **Completeness and membership**: missing shard, duplicate index, wrong count, differing coverage hash, log from
   another SHA, unreadable log, stats that do not reproduce their `msi`, a shard file list that differs from the
   recomputed plan (missing, extra, moved file), the union of lists short of the planned set, a file in two shards, a
   mutant whose source file is not in its shard's list, the same mutant identity in two shards, an unknown top-level or
   `stats` log key, an empty shard's synthetic log, the plan recomputation using a different in-scope set: each a
   crash naming the shard (and file). The planner drops out-of-scope files and refuses N above the file count.
7. **Planner**: `InfectionRunScope` returns Full for each `InfectionFullRunTriggers` path and for no merge base, Diff
   otherwise; the planner's output equals the lane's decision for the same fixtures (one shared class, one table of
   cases).
8. **Workflow files** (the existing template tests are **rewritten**, not extended:
   `tests/Small/PipelineTemplateRunsOncePerPushTest.php` pins the job keys to exactly `qa` and `coverage-report` and
   binds the id `qa` to the pipeline body (PHP detection step, timeout, `useInfection` env); the design moves that body
   to `pipeline` and makes `qa` the gate, so its job-key, body and `needs` assertions change, and its "a job of its own
   costs a runner and a check name" message is updated to say why the new jobs exist): `qa.yml` equals the template;
   exactly one job named `QA Pipeline`; it has `if: ${{ !cancelled() }}` (never `always()`), explicit result checks, and
   lists every other job in `needs`; `coverage-report` needs `pipeline`; the pipeline body keeps its PHP detection,
   timeout and `useInfection` expression on `pipeline`; `fail-fast: false`; every job has
   `timeout-minutes`; upload steps use `if: always()`; shard uploads set `overwrite: true` and exclude `tmp/`;
   `infection-coverage` and `mutate` are gated on `sharded` (the matrix is never evaluated with an empty list); the
   handoff artifact names differ from `coverage`; `coverage-report` still needs the job that uploads `coverage`; a
   `workflow_dispatch` run is `sharded=false`.
8a. **Configuration floors and selection evidence** (the two detectors the second review found missing): the strip test
   of section 3 step 1 (all four keys, survivors, serial unchanged, join reads the original); the join fails on a
   planned file with executed lines that is absent from the log and not accepted as zero-mutant, on a `perFile` that
   does not equal the count recomputed from the log, on `perFile` keys that differ from the recomputed plan, on a
   `running` or non-zero-exit `shard.json`, and the shard crashes on a path that is not its own `realpath()`; the
   floor judge: strict less-than on the rounded score, floor 0 off, `maxTimeouts` from the original configuration.
8b. **Entry point registration**: `ComposerBinManifestTest` covers `bin/infection-plan` (declared, not ignored,
   executable); a test that the workflow's scope step calls `$(composer config bin-dir)/infection-plan`.
9. **Large**: end to end on a tiny fixture project with the real phar: a serial full run against coverage + 3 shards +
   join, with the equal set and verdict of the tolerance rule in section 4, step 5 (timeouts may move between the detected
   classes), and one case where a planned path is deliberately unselectable (a symlinked copy of a source root), which
   must fail the shard or the join rather than pass short.

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
- File selection with about 300 absolute paths: measured with two paths only. The whole list is roughly 20 KB of
  arguments; a shard carries about 1/N of it, far under any limit. Substring matching is settled from source (section
  0a) and made harmless by absolute paths plus the join's membership check; the multi-value and positional equivalence
  are Task 2.0c items.
- A planned file that Infection silently does not select; made a crash by the pre-run `realpath()` check and the join's
  per-file evidence (section 4, step 1b), whose zero-mutant rule is fail closed until Task 2.0c (8) finds a trace.
- Coverage path validity across jobs: assumed identical workspace path; checked at runtime (section 2).
- Timeouts count as detected and are load sensitive (MEASUREMENT D, F); separate machines avoid the same-host
  contention that invalidated one run, but a loaded runner can still produce flaky timeouts, as a serial run can.
- Hosted runners are smaller than the measurement host; real per-shard time, and whether N fits under the
  file-granularity ceiling at all, is unproven until Task 2.0a (section 1).
- One more moving part in the required path; mitigated by the single gate and its completeness checks.

Open questions for the Owner:

1. Is a lane setting `infectionShard` (stage, index, count; the lane builds its own argv) consistent with "not a tool
   proxy"? The design says yes: no Infection flag is exposed.
2. Consumer default: the shipped template off unless `PHP_QA_CI_INFECTION_SHARDS` is set (proposed), or on?
3. Shard count and CI compute budget for this repository (to be fixed by Task 2.0a; above about 9 the biggest file is
   the floor).
4. Closed from source: strict less-than on the rounded score, a floor of 0 off (section 0a); no longer open.
5. Is a gate job with a different job id acceptable for the repository's branch protection, given the check name stays
   `QA Pipeline`? (Needs a look at the required-check configuration.)
6. Should the join's failure modes reuse the existing identifier, or get a page for "shard handoff broken"?
7. None on the changed-lines question: this design does not depend on it.

Answers recorded from the review: question 1 is answered yes by the architecture rule that a need for another flag is
a configuration setting (a typed `with*()` on `QaConfigBuilder`); question 5 is answered by the repository's
branch-protection script, which requires the check by its name `QA Pipeline` (not its job id), and by the existing test
that a workflow produces every required name, so the gate keeps that literal name. Question 6 leans yes: a page for
"shard handoff broken" is cheap and gives the failures a stable identifier.

## 8. Tasks

The phased task list lives in [PLAN.md](./PLAN.md) (single home); this design adds nothing to it.
