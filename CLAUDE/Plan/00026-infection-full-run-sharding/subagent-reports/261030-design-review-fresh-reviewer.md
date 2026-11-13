# Review of PR #373: DESIGN.md for sharding the full Infection run (Plan 00026, issue #344)

Scope: `CLAUDE/Plan/00026-infection-full-run-sharding/DESIGN.md` (287 lines, new) against PLAN.md, MEASUREMENT.md, the
Infection lane, the workflow and template, and the repo rules. Design only; no code in the diff.
Limits: no `php` binary in this worktree, so Infection 0.35.8 behaviour (phar internals, `--filter` matching, the
zero-mutant log) could not be run or read; those claims are marked UNVERIFIED and need Task 2.0c.

Outcome at review time: two MAJOR findings, both cheap to fix in the text, since addressed in the revision.

## Claims checked

Verified true:

- `.github/workflows/qa.yml` and `templates/github-actions/php-qa-ci.yml` are identical (`diff` clean); single job `qa`,
  `name: QA Pipeline`, `timeout-minutes: 45` (qa.yml, `jobs.qa`).
- `InfectionTool` is 710 lines; `run()` at InfectionTool.php:113-194 is in the order the design describes
  (Xdebug skip :119, runConfig :125, base resolve :132, resolveDiffScope :142, checkPreloadedSource :150,
  ensureCoverage :157, argv :162, `DirectoryTree::empty(var/infection)` :166, run :171, judgeKills :180).
- Full-run decision lives in `resolveDiffScope()` (InfectionTool.php:458-465, `configChanges` non-empty returns `[]`),
  which also does the uncommitted-changes refusal (:435) and `git diff` (:440); extracting an `InfectionRunScope` is
  feasible but is more than "is it full?" (see MINOR 3).
- `suiteNeverStarted()` is "every judged kill is vacuous" (KillJudgementDto.php, `count(vacuous) === judged`); the
  per-shard false-crash argument in DESIGN section 3 step 6 is sound. `withCounts()` (VacuousKillDetector.php:~105-130)
  tries detected with and without timeouts and matches against the log's own `msi`, as DESIGN section 4 says; the
  rounding is two places, half away from zero (KillJudgementDto::share), as stated.
- `InfectionOptionsDto` refuses mode/base contradictions with `LogicException` (InfectionOptionsDto.php:40).
- `infectionShard` as a typed builder setting fits the "not a tool proxy" ruling (CLAUDE/architecture.md:105-107:
  "a need for another flag is a configuration setting (a typed `with*()` on `QaConfigBuilder`)"). Open question 1 can be
  answered yes, and the doc could cite that line.
- Open question 5 is already answered by the repo: `scripts/setup-branch-protection.bash:173` requires the context
  `"QA Pipeline"` by job NAME, and `tests/Small/RequiredStatusChecksAreProducedTest.php` asserts a workflow produces
  every required name (it treats matrix/expression names as dynamic, so the gate must keep the literal name, as the
  design does). Test 8 in DESIGN section 6 partly exists already.
- MEASUREMENT figures quoted (19,590 mutants, 310 files, max 2,009 = 10.3 percent, 661 s, 15 to 20 s start cost,
  359 s coverage) match MEASUREMENT.md. "No single file exceeds the average shard up to about 9 shards" is correct on
  mutant counts (1/9 = 11.1 percent > 10.3; 1/10 is not). LPT bound and determinism claims are right.
- `index.xml` `<totals><lines executable executed>` is PHPUnit's coverage-xml format (not re-run here).

## Findings

### MAJOR 1: the join verifies metadata, not mutants, so a lost or doubled file is not caught

DESIGN section 4 step 1 (completeness) checks directory count, indices, and that every `shard.json` agrees on count,
coverage hash and "file-list hash". Problems:

1. "file-list hash" is ambiguous: if each shard hashes its own list the hashes differ by construction; if it hashes the
   whole plan, it proves nothing about what the shard actually mutated.
2. The gate job downloads only shard artifacts (section 5), not `index.xml`, so it cannot recompute the plan and cannot
   check that the union of shard lists equals the planned set (a missing or mis-split file, a non-deterministic plan
   input such as a different `source.excludes`, is invisible).
3. Nothing checks the log content against the shard's list. The design's own risk list admits `--filter` was measured
   with two paths only and its accepted form is unknown (DESIGN section 7). Infection's `--filter` is not known to be an
   exact path match (UNVERIFIED; if it is a substring/path-fragment match, one shard's filter can pull in another
   file, and two shards then mutate the same file and the sum double-counts). The "same mutant set by per-file
   independence" argument (section 4 step 5) assumes exact matching and is exactly what is unproven.
   Fix in the text: (a) the join must recompute the plan from the manifest-matched `index.xml` (put `index.xml` in a
   small separate artifact, or in `coverage-manifest.json`) and compare each shard's recorded list to it; (b) the merger
   must verify every mutant's `originalFilePath` is in its shard's list and that no mutant identity
   (file, line, mutator, replacement) appears in two shards; (c) add both as tests in section 6 item 6; (d) make Task 2.0c
   prove `--filter` exact-match semantics with a name that is a prefix/suffix of another file, before any code.

### MAJOR 2: "re-run failed jobs" does not work with the artifact scheme as written

DESIGN section 4 ("A shard crashes") and section 5 rely on rerunning one failed shard. Shards upload
`infection-shard-<i>` with `if: always()`, so a crashed shard that wrote a log has already created that artifact in the
run. A rerun attempt uploading the same name in the same run is rejected by `actions/upload-artifact` v4 and later
(name conflict, unless `overwrite: true`); the template pins `upload-artifact@v7` (qa.yml, "Upload test results").
Then the rerun shard goes green but its upload fails, or the gate reads the stale crashed log. Task 3.1 ("Rerun one
shard to show re-join works") would catch this late. State the rule now: `overwrite: true`, or include
`github.run_attempt` in names and have the gate pick the newest per index; and say which the join prefers when a shard
log from attempt 1 and 2 both exist. Same section: the 1-day retention of the coverage handoff means a rerun after a
day fails at download; say that this is loud (it is) and acceptable.

### MINOR 1: a shard whose files produce no mutants may be treated as a crash

DESIGN section 3 step 7 makes a missing/unreadable log a crash, and section 4 step 4 refuses zero total mutants. A shard
of files with covered lines but no generated mutants (trivial classes; `global-ignoreSourceCodeByRegex` in
`qaConfig/infection.json`), or an empty shard when N exceeds the file count (the design tests for it, section 6 item 2),
may produce no JSON log or a log with `msi` 0 (KillJudgementDto::share returns 0.0 over 0). Whether Infection 0.35.8
writes `logs.json` and exits 0 with zero mutants is UNVERIFIED. Decide: an empty-plan shard writes a synthetic
all-zero log itself and never starts Infection; a non-empty shard with a missing log is the crash. Also a sanity
refusal when N is larger than the number of files with mutants.

### MINOR 2: the merger hand-lists log keys

Section 4 step 2 names eight mutant lists and ten stats counts. A list Infection 0.35.8 emits that is not named
(for example a static-analysis kill list, if present) would be dropped silently from the merged log, though counts still
sum. Make the merger iterate all array-valued top-level keys except `stats`, fail on a key it cannot classify, and pin
the key set with a fixture from a real 0.35.8 log (Task 2.0c). Also note `VacuousKillDetector::COUNTS`
(VacuousKillDetector.php:~62) excludes `escapedCount`; the design's merge sum includes it, which is right, but the
shared-constant refactor in section 6 must keep the two lists distinct.

### MINOR 3: the planner (`bin/infection-plan`) is under-specified

`resolveDiffScope()` needs the diff-base resolver with its environment, the strict uncommitted-changes check
(InfectionTool.php:435), git, and the source scope; "Full(reason) | Diff | Skip(reason)" must carry the Skip and
refusal cases too (a refused or skipped lane must not turn into `sharded=true`). Say what `scope` prints when the lane
would refuse or fail, and that the scope job needs the same checkout depth and composer install (it does:
section 5). A new `bin/` entry also needs listing wherever the repo names its entry points (recent commit 6fe9e97c
did this for `bin/hooks-daemon-required-handlers` in `qaConfig/qa.php`); add to Task 2.6.

### MINOR 4: weight set should be intersected with the mutation scope

`index.xml` lists every file PHPUnit covered, including those Infection's `source.excludes` and the lane's ignored
paths remove (qaConfig/infection.json excludes `/ComposerPlugin/`; `IgnoredPathsInfectionConfig` adds more). Plan only
files inside `InfectionSourceScope`, else they inflate weights and empty-shard counts and the `--filter` list carries
files Infection never mutates.

### MINOR 5: workflow details

- Name the handoff artifact so it cannot collide with the existing `coverage` artifact (qa.yml "Upload coverage
  reports", read by the `coverage-report` job); the design also names a job `coverage`. Keep `coverage-report`
  `needs` pointing at the job that uploads `coverage` (the `pipeline` job), not only the gate.
- Shard uploads of `var/qa/infection/` include `tmp/` (`qaConfig/infection.json` `tmpDir`), which is large and useless;
  upload only the JSON log, `shard.json` and text logs.
- The join stage must not run `DirectoryTree::empty(var/qa/infection)` (InfectionTool.php:166) over the downloaded
  inputs; the shards live under `var/qa/infection-shards/` (fine) but the merged log goes to `var/qa/infection/`, which
  is emptied first; order matters.
- `workflow_dispatch` with a single tool `infection` is documented as `sharded=false`, so a manual full run still hits
  the 45 minute limit; say so.
- A skipped `mutate` matrix whose `fromJSON` input is empty must be guarded by the job-level `if` (it is gated on
  `sharded`, but test 8 should assert the matrix is never evaluated with an empty list).

### MINOR 6: documents disagree with each other

- PLAN.md Overview says "the mutants are divided by count rather than by file"; DESIGN shards by file using executed
  lines as a proxy. Update PLAN.md (same PR or a follow-up) so the Goals/Overview and the task list match DESIGN
  section 8; DESIGN's "Phased task list for PLAN.md" is not yet merged into PLAN.md, whose tasks are 1.1 to 3.1.
- "about 20 KB" argv for ~300 paths (section 7) is the whole list; a shard carries roughly 1/N of it. Immaterial to
  limits, but the number is unclear.
- Section 1 sizing: "N about 6 to 8" rests on a duration extrapolated from a loaded multi-core development host, which the design itself
  says is not a CI-runner measurement; fine as stated (Task 2.0a), but "6 to 8" appears in open question 3 as a proposal
  too. Keep it as "to be fixed by 2.0a" only.

### Not findings (checked, fine)

- Repo rules: no suppressions or baseline proposed; no time estimates of work (only measured/extrapolated durations);
  wording is generic; PHP logic in `src/` with a thin `bin/` entry, workflow logic is declarative `if:` (no Bash
  logic); new classes `final readonly`, `@internal`, `list<T>`; tests precede code in section 8; new constants instead of
  repeated literals. Defence Before Fix is not strictly engaged (a feature, not a defect), and the design's tests-first
  ordering and workflow-shape tests (section 6 item 8) serve as the detector for the new failure modes; reusing
  `phpqaci.infection` (open question 6) is acceptable but the "shard handoff broken" page would be cheap and give a
  stable identifier; lean yes.
- Gate with `if: always()` and explicit failure/cancelled checks is the right construction (a skipped required job
  reports as passing); name is kept literal, which the existing test requires.
- Dropping the vacuous-kill judgement per shard and running it once on the merged log is correct and well argued.
- Coverage-path validity across jobs is checked at runtime with a named crash; weights proxy risk is declared with a
  measured fallback.

## Outcome at review time

Two MAJOR findings, no BLOCKERs, since addressed in the revision.

MAJORs: (1) join checks metadata only; add plan recomputation, per-mutant file membership, duplicate-mutant detection
and an exact `--filter` semantics proof in Task 2.0c. (2) rerun of a failed shard conflicts with same-name artifacts
(`upload-artifact` v4+); define `overwrite: true` or attempt-suffixed names and the join's pick rule.
BLOCKERs: none.
