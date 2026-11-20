# Review 3 of PR #373: design for sharding the full Infection run (issue #344)

Reviewed at `origin/chore/plan-00026-design` (2c456e78): `CLAUDE/Plan/00026-infection-full-run-sharding/{DESIGN,PLAN,MEASUREMENT}.md`
against the lane (`src/Pipeline/Lane/InfectionTool.php`, `Infection/*`), `QaConfigBuilder`, `composer.json`, `.github/workflows/qa.yml`
(byte-identical to the template, checked with `diff`), the workflow tests, `qaConfig/infection.json`, and Infection 0.35.8 source read
through the GitHub API (no PHP binary, nothing executed). Formed before reading the earlier review records. DESIGN line numbers are the
design's own. Read-only apart from this file.

## Verdict

CHANGES NEEDED. No BLOCKER. Two MAJOR findings: the join proves the planned files were mutated, but nothing proves the planned set is
the set a serial run mutates (a file the planner wrongly drops is lost silently); and the zero-mutant evidence rule may be unsatisfiable,
with no defined outcome if it is. Both are cheap to fix in the text.

## Claims I tried to disprove and could not (VERIFIED)

- Infection 0.35.8 `BasicSourceCollector::collect()` throws `NoSourceFound` on zero files (:67); the filter is a `RealPathFilterIterator`
  built at :163 (any-of semantics over `preg_quote`d values matched against `getRealPath()`); an unmatched value is dropped silently while
  other values still match. `Engine::execute()` calls `runMutationAnalysis()` at :100, before the `try` at :102 whose `finally` (:112)
  dispatches `ApplicationExecutionWasFinished`, so a no-file run writes no log (inference stands). `RunCommand.php:398-400` turns a
  filtered `NoSourceFound` into success.
- `ConfigurationFactory.php:379-403`: `minMsi`, `minCoveredMsi`, `maxTimeouts`, `ignoreMsiWithNoMutations` fall back to the schema (the
  `infection.json`) when the command line omits them. The strip in DESIGN section 3 step 1 is therefore needed and sufficient for those
  four keys. The lane always passes an int floor (`InfectionArguments::full()` :38-39, `InfectionOptionsDto` ints), so in a serial run
  the file's `minMsi` never applies; "lane setting over the file" (section 3 step 1) is true but the file branch is dead for the two MSI
  floors (not an error).
- `MinMsiChecker.php`: `checkMetrics` :59, `ignoreMsiWithNoMutations` branch :76, `isMsiInsufficient` :123: strict `<`, floor 0 off, both 0
  skips the whole check. `getTestedMutantsCount()` = total - skipped - ignored (`MetricsCalculator`), matching the design's formula use.
- `JsonReporter` stats keys and the eight list keys are exactly as DESIGN section 4 step 2 states (`uncovered` empty in covered-only mode;
  no `notCovered` or `skipped` list). `--filter` deprecation (`SourceFilterOptions` :131-137) and the exclusivity asserts hold.
- Workflow facts: `PipelineTemplateRunsOncePerPushTest.php:40,112,148` pins keys `['qa','coverage-report']`, binds `qa` to the pipeline body
  and `coverage-report needs qa`; `noScriptOfAWorkflowOrTemplateHoldsAnExpression` exists (:212); `RequiredStatusChecksAreProducedTest`
  treats a matrix job as dynamic and `scripts/setup-branch-protection.bash:173` requires `QA Pipeline` by name; `release.yml:41,69` keys
  on the workflow name "PHP QA Pipeline" and conclusion only; `ComposerBinManifestTest` has the three declared/ignored/executable
  tests; `qaConfig/qa.php:100-101` lists entry points; `composer.json` `bin` (:120-149) has no `infection-plan` yet and the design adds it.
- LPT with strictly positive weights gives every shard at least one file when files >= N (a zero-load shard is always the strict
  minimum), so "N \<= planned files" is sufficient to prevent an empty shard.
- MEASUREMENT arithmetic (19,590/528 x 90 s = about 3,340 s; 2,009/19,590 = 10.3 percent; ceiling about 9) holds.
- Repo rules: no suppression or baseline; no time estimates (only measured or extrapolated durations); wording generic (only
  repository-relative paths); PHP in `src/` with thin `bin/`; `infectionShard` as a typed `with*()` setting is within the
  "not a tool proxy" ruling (`CLAUDE/architecture.md:100-111`).

## Findings

### MAJOR-1: nothing proves the planned file set equals the set a serial run mutates

DESIGN section 1 lines 102-107 ("Only files inside the mutation scope are planned") and section 4 steps 1, 1a, 1b. The join recomputes the
plan itself and checks every planned file was mutated. It cannot see a file that Infection's collector would select but the planner
excluded, because that file is in no shard's list and so is never passed to any Infection process: its mutants are simply never
generated, the totals add up, and the verdict can differ from serial. The "equivalence argument" (section 4 step 5, "same mutant set")
rests on the planner's scope being identical to Infection's, and the only proof is the single Phase 3 comparison on one commit.

The scope is not computed by Infection: the repository's existing `InfectionSourceScope` (src/Pipeline/Lane/Infection/InfectionSourceScope.php:67-83)
is a hand-written replica of Symfony Finder (`notPath` substring or regex, dot and VCS directories); Infection's real collector
(`BasicSourceCollector::createFinder`) also applies `Finder::exclude()` and re-applies a `PathFilterIterator`, and it resolves relative
`source.directories` against the config file's directory. A divergence where the replica is narrower is a silent loss; where it is wider
it fails closed through 1b. The design names no mechanism, test or runtime check on the scope itself (section 6 item 7 tests the full-run
decision, not the file scope).

Fix: (a) specify that the planner derives the scope with the same Symfony Finder calls Infection makes (or reuse and extend
`InfectionSourceScope` with a table test against a real Finder over a fixture tree with excludes, dot directories, symlinks, nested
`exclude` and regex `notPath`); (b) have the coverage stage record every index.xml file it drops with the exclude entry that dropped it
and have the join require each dropped file to be explained by an exclude, ignored path or a non-`.php`/outside-directories reason
(a file under a source directory with no reason is a failure); (c) add the Phase 3 and Large-test assertion that the planned set equals
the set of `originalFilePath` values plus zero-mutant files of the serial run.

### MAJOR-2: the zero-mutant fail-closed rule may be unsatisfiable, and the design has no outcome for that case

DESIGN section 4 step 1b, lines 267-272. Until Task 2.0c (8) finds a per-file trace, a planned file with executed lines and no mutants
fails the join. Files with executed lines and no mutants are plausible in any tree of this size (a class whose only executable line is an
empty or promoted constructor, an enum, a trivial accessor under `global-ignoreSourceCodeByRegex`), and the covered-only generator drops
untested files silently (`FileMutationGenerator`, as the design itself cites at :63-65). If Task 2.0a counts even one such file and 2.0c
finds no trace, every sharded run fails its gate, which the design acknowledges only as "a trace must be found first". If none exists
the design has no fallback, yet the plan proceeds to build in Phase 2 on that assumption.

Fix: state the fallback now. Candidates: accept a zero-mutant planned file only when the serial-plan weights file or a cheap per-file
check (for example Infection's mutant count for that single file via a one-file shard run in the coverage stage, which has the time) says
zero; or give the zero-mutant files an explicit allow-list produced and reviewed as part of the run (an Owner decision, not a silent
edit). Also make Task 2.0a and 2.0c (4) and (8) a hard gate in PLAN.md: "do not start Phase 2 unless ...".

### MINOR-1: the "absolute paths cannot be substrings" argument is false

DESIGN section 0a lines 38-41: "two distinct paths ending in `.php` cannot be substrings of one another when both start with the same
root". `/r/src/A.php` is contained in `/r/src/A.php.php` (and in `/r/src/A.php/x.php` for a directory named like a file). The matcher is
unanchored (`'/' . preg_quote() . '/'`, RealPathFilterIterator `toRegex`). The consequence is caught (1a rejects a foreign mutant) but as
a loud false failure, not the "safe" the text claims, and Task 2.0c (2) tests only prefix/suffix of another path, not this. Reword, and
add the `Foo.php` / `Foo.php.php` case to 2.0c; the planner should refuse such a tree up front or anchor the value (Infection accepts a
delimited regex such as `#^/abs/A\.php$#`, which `isRegex` passes through unchanged).

### MINOR-2: rounding precision is not "configuration", it comes from the floor's text

DESIGN section 0a lines 75-77 and section 4 step 2: "precision is configuration, read from the same resolved configuration". In 0.35.8
`MsiParser::detectPrecision()` (Console/Input/MsiParser.php) takes the precision from the number of decimals in `--min-msi` and
`--min-covered-msi` (default 2), and `RunCommand.php:436` passes it to the factory. The lane passes ints, so it is 2 in a serial run and in
a shard, but the join must take it from the lane's floors (2, or the decimals of a future non-int floor), not from `infection.json`.
State that, with a test.

### MINOR-3: the gate and the pipeline job both upload `test-results`

DESIGN section 5 gate bullet: the merged log goes to `var/qa/infection/` "so the artifact upload ... sees a log shaped like a serial one".
The existing upload step (qa.yml "Upload test results") uploads `test-results` with `var/qa/phpunit_logs/` and `var/qa/infection/`; the
design moves that step with the pipeline body to `pipeline`, and the gate, if it copies the upload step, would upload a second artifact
named `test-results` in the same run, which v4+ rejects (the exact failure the design handles for shards). Name the gate's artifact
(for example `infection-merged-log`) and pin it in the workflow test. Also `infection-coverage` uploads need `overwrite: true` on a
rerun, as the shard uploads do. The download layout is `infection-shard-<i>/` per artifact with `download-artifact`, not
`var/qa/infection-shards/<i>/` unless the step maps it; specify the mapping.

### MINOR-4: the scope job's Xdebug and effective-config dependency is unspecified

`QaConfigBuilder::build()` (:448) sets `infection.enabled = useInfection && phpUnitCoverage && xdebugEnabled`, and the lane returns SKIPPED
without Xdebug (InfectionTool.php:119). DESIGN section 5 gives the scope job "checkout, PHP, deps" and `bin/infection-plan` reads
`useInfection`; if the planner builds the config the lane does, a scope job without Xdebug decides `sharded=false` on every run and the
feature never engages (silently). The shard and join exemption from the Xdebug skip is stated (section 3 end) but `Pipeline.php:316` also
gates the tool on `infection->enabled`. State that the planner reads the raw `useInfection` env (not the effective flag) and that the
join and coverage stages are runnable with the gate's setup, and add a workflow-test or planner test for it.

### MINOR-5: sizing and thread count; no balance acceptance criterion

Measured rate: 528 mutants in 90 s at 11 threads is about 1.9 thread-seconds per mutant; 19,590 mutants is about 37,000 thread-seconds.
A hosted runner running the lane's default `halfCpuThreads` (2 threads on 4 vCPUs) needs about 39 minutes per shard at N = 8 and still
exceeds the 45 minute budget with checkout, install and start cost at N = 9, the file-granularity ceiling; with 4 threads it is about 19
minutes. The design never says shards should use all CPUs (the lane default is half), nor includes it in Task 2.0a. Add: shard threads
are a measured part of 2.0a. Task 2.0b correlates executed lines with mutant count, but shards balance on wall time (Large-test-covered
mutants cost far more per mutant than Small-test ones); correlate with time where the debug log allows, and give Phase 3 an acceptance
criterion (slowest shard within a stated factor of the mean).

### MINOR-6: other gaps in the task list and wording

- Comma and glob characters: `PlainFilter` splits on `,` (design cites it); an absolute path containing a comma would be split into two
  values. The planner should refuse such a path (add to the realpath check list in section 3 step 3). Sort order: say byte-wise `strcmp`
  on the path, not locale order, for the deterministic plan.
- Memory: the JSON log entries carry `originalSourceCode` and `mutatedSourceCode` (whole-file text in Infection), plus `diff` and
  `processOutput`, per mutant; the join holds every shard's log plus identity keys. Record the serial log size in 2.0a and give the gate
  an explicit memory limit; streaming or hashing identities per entry is advisable.
- PLAN.md has no Owner sign-off task: issue #344 records the choice between its options 1 to 4 as an Owner decision ("nothing is built
  until it is made") and its comment thread shows none; DESIGN open questions 2, 3 and 6 are also undecided. Add a Phase 1 task that
  records the decision and answers (consumer default, shard count, identifier page) before Phase 2.
- Defence Before Fix record: the plan has no clause 3.1 record (class, hazard, two search techniques, bounding) for the class behind #344
  ("a required check whose work cannot finish inside its job limit") and uses tests as the detectors for the gate shape. The repo has
  workflow-shape tests as precedent, but `CLAUDE/DefenceBeforeFix.md` says a test must not serve as the Detector; record why a test is
  right here or name the bespoke lane. The identifier-page decision (Task 2.1 "decide whether") should be made, since a new check needs
  an identifier, a page and an index entry (`CLAUDE.md` DBF rule).
- Docs the task list omits: `docs/ci.md`, `docs/github-actions.md`, `docs/pipeline.md`, the template header comments (job `qa` is now the
  gate), and `docs/phpstan-rules/README.md` if a page is added. Task 2.8 names only `docs/tools/infection.md` and the changelog.
- Scope of #303: the design says it shares #344's root, but a manual `workflow_dispatch` full run and any serial full run on the default
  branch stay bound by the 45 minute limit (section 5 documents the first). State that #303 is not closed by this plan.
- The sum check "sum(perFile) equals the total of the log's mutant entries" (section 4 step 1b) compares against list entries, not
  `totalMutantsCount`, which also counts skipped mutants that appear in no list; a file whose mutants are all skipped looks zero-mutant.
  Say which, and record skipped counts per shard.

## Focus checklist

- Join loses/doubles no mutant: per-shard lists, plan recomputation, membership (1a) and cross-shard duplicate identity are sound for
  the planned set; the open hole is MAJOR-1. Double counting is excluded by 1a; the false-failure case is MINOR-1.
- Every planned file accounted for (mutated, zero-mutant, crash): mutated and crash yes; zero-mutant is MAJOR-2.
- Config floors cannot leak: the four-key strip is correct against `ConfigurationFactory`; the floors come from the lane (MINOR-2 for
  precision). No other verdict-bearing key found at top level.
- Rerun and artifacts: `overwrite: true`, per-index naming, commit and hash pinning are sound; MINOR-3 is the remaining collision.
- Empty and zero-match shards: coverage-stage refusal plus guard; LPT never leaves a shard empty when N \<= files. Sound.
- Gate name and branch protection: sound (name matched, `!cancelled()`, explicit result checks, `coverage-report` retargeted).
- Workflow shape and pinned tests: the rewrite of `PipelineTemplateRunsOncePerPushTest` is correctly foreseen.
- Task list: tests-first ordering is right (2.1 and 2.1a before 2.2 onward); incomplete per MINOR-6 (sign-off, docs, DBF record).

## Earlier reviews: which findings are truly resolved

Read after forming the above. Review 1 (`261030-design-review-fresh-reviewer.md`) and Review 2
(`261030-design-review-second-fresh-reviewer.md`):

- R1 MAJOR 1 (join checked metadata): resolved by plan recomputation, 1a membership and duplicate-identity checks. The residual hole is
  the planner-scope one (my MAJOR-1), which neither review raised.
- R1 MAJOR 2 (rerun artifact conflict): resolved for shards (`overwrite: true`, per-index names); not for the gate's own and the coverage
  artifacts (MINOR-3).
- R1 MINOR 1/2/3/4/5/6: resolved (synthetic empty log, key-iterating merger with fixture, planner outputs and registration,
  scope intersection, artifact names and `tmp/` exclusion, join ordering, PLAN alignment).
- R2 MAJOR-1 (config floors): resolved by the strip and original-config floors, verified against source above.
- R2 MAJOR-2 (silent non-selection): partly resolved. Pre-run `realpath()` check and per-file evidence added, but evidence for zero-mutant
  files is "fail closed" with no fallback (my MAJOR-2), so only the mutant-bearing half is closed.
- R2 MAJOR-3 (`composer.json` `bin`): resolved (section 5a registration; ComposerBinManifestTest named).
- R2 MINOR-1 (log keys), MINOR-2 (citations; spot-checked :67, :163, :100-112, :123 now correct), MINOR-3 (floor comparison closed),
  MINOR-4 (`shard.json` ordering), MINOR-5 (refusal in the coverage stage), MINOR-6 (workflow shape, `!cancelled()`, test rewrite),
  MINOR-8 (`@api` trailing parameter): resolved.
- R2 MINOR-7 (sizing may not fit hosted runners): acknowledged as unproven, not resolved; see MINOR-5 above (thread count and balance
  criterion still missing). Tolerance rule for timeouts: resolved (section 4 step 5).

Outcome at review time: MAJOR-1 (planned set not proven equal to Infection's serial source set; silent loss if the planner scope is narrower), MAJOR-2 (zero-mutant evidence rule may be unsatisfiable; no fallback). BLOCKER: none.
