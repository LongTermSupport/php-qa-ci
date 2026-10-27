# Infection speed-up measurement

Measured on this repository's `php8.5` line, to choose how to bring a full Infection run inside a CI job limit. The
sample is three source files (528 mutants). The host was shared and loaded, so only the large effects are reliable.

Setup: 22 CPUs, load average 40 to 70 during the runs. PHP 8.5.12 with Xdebug 3.5.3, Infection 0.35.8. Threads were 11
(half the CPU threads, the lane's default) for every run. Infection ran as the lane runs it: no Xdebug, `nice`,
`--skip-initial-tests --coverage --threads=11 --configuration`, the diff-mode flavour (`--with-uncovered`, source files
as positional paths), `--log-verbosity=all`, JSON log on. The scratch `infection.json` copies were the shipped
`qaConfig/infection.json` with absolute paths (timeout 10 unless stated).

## Coverage generation (one PHPUnit process, Xdebug coverage-xml, no ParaTest)

- core (`tests/Small` only): 151 s
- full (Small and Large): 359 s

## Mutant counts (whole-tree `--dry-run`, covered code only; 661 s wall just to generate and log, no tests run)

- 19,590 mutants over 310 source files. Median file: 36 mutants; largest: `NumericStringArrayKeyCollector`, 2,009.
  A whole-tree run at the sample's rate (528 mutants in 90 s) extrapolates to roughly 55 minutes on this host (not
  measured; consistent with exceeding a 45-minute CI limit).
- Sample (a stable pick: small is a rule near the 25th percentile, medium near the 85th, large is the lane's own class
  and a file with Large tests):
  - small: `src/PHPStan/Rules/ForbidDangerousFunctionsRule.php`, 13 mutants
  - medium: `src/Psr4Validator.php`, 148 mutants (146 covered, 2 uncovered)
  - large: `src/Pipeline/Lane/InfectionTool.php`, 367 mutants (366 covered, 1 uncovered)
  - sample total 528 (3 uncovered)

## A. Baseline: 90 s

528 mutants: killed 515, timed out 4 (counted as detected), escaped 6, not covered 3. MSI 98.3, covered MSI 98.86. The escapes were all in `InfectionTool.php`.

## B. `core` testsuite (tests/Small only) fed to Infection: 73 s (coverage also from core only)

A scratch `phpunit.xml` with one suite `core` = `tests/Small`; `phpUnit.configDir` in the scratch `infection.json`
points at it; coverage from the core run. Same 528 mutants, killed 518 (1 timeout), escaped 6, not covered 3. MSI 98.3,
covered 98.86. Mutants lost against A on the sample: 0.

Whole-tree estimate of what B would lose, from the two coverage runs: 401 of 19,590 mutants (2.0%) sit on lines covered
only by Large tests, in five files, which would become "not covered". Not measurable from coverage: a mutant on a line
covered by both a Small and a Large test that only the Large test kills.

## C. `--only-covering-test-cases`: 43 s

Same 528 mutants; killed 513 (1 timeout), escaped 11, not covered 3. MSI 97.35, covered MSI 97.9. Lost against A: 5, all
in `Psr4Validator.php`. All five are killed in A only by a test that runs the code but gets no coverage credit for those
lines, so the option, which runs only the line's covering test cases, drops it. A real kill the option cannot see.

## D. Timeout lowered

- 5 s: 55 s, MSI 98.3, covered 98.86, 2 timeouts, lost 0, no new false escapes.
- 3 s: 70 s, MSI 99.24, covered 99.81, 78 timeouts (A had 4). 72 mutants killed outright in A are now timeouts, and 5
  mutants that escaped in A now score as detected. A timeout counts as detected, so 3 s inflates the score and hides real
  test gaps.
- Timeout counts vary run to run with host load. A (90 s) against D at 5 s (55 s) is a single run each on a loaded host,
  so it cannot separate a real gain from noise, and no speed-up is claimed for it. Under heavy contention the 10 s limit
  alone produced 136 timeouts (see F).

## E. `--git-diff-lines` (base: the commit before a 2-line refactor): 3 s

2 mutants, both killed, MSI 100, against 367 for the whole file. Coverage was the pre-edit run and was accepted although
the file had changed, so a stale coverage directory is trusted silently.

## F. Sharding

- `--filter` takes a comma-separated list of paths: `--filter=a.php,b.php` mutated exactly those two files.
- Two shards on one host with 11 threads each: 225 s and 35 s, and the first had 136 timeouts (counted detected: MSI
  98.91 instead of 98.09) and 3 escapes instead of 6. Two 11-thread runs on one loaded host corrupt the result, so
  same-host parallelism is not usable.
- Shard 1 alone afterwards: 75 s (367 mutants, MSI 98.09, covered 98.36, 6 escapes, 1 timeout); shard 2 took 35 s. The
  sum is 110 s against 90 s for the one run: each Infection process has a fixed start cost (autoload, mutant generation,
  15 to 20 s here). Sharding therefore only helps across separate CI machines, and each needs the coverage (359 s to
  generate here), so coverage has to be generated once and handed to the shards as an artifact.
- Balance matters: mutants per file are very skewed (median 36, maximum 2,009 in one file), so shard by mutant count, not
  file count.

## Summary (sample of 3 files, 528 mutants, 11 threads, loaded host)

| Variant                                 | Wall      | MSI   | Covered MSI | Lost against A                                          |
| --------------------------------------- | --------- | ----- | ----------- | ------------------------------------------------------- |
| A baseline (lane flags)                 | 90 s      | 98.3  | 98.86       | -                                                       |
| B core suite (Small only, own coverage) | 73 s      | 98.3  | 98.86       | 0 (tree-wide estimate 401 of 19,590)                    |
| C `--only-covering-test-cases`          | 43 s      | 97.35 | 97.9        | 5                                                       |
| D1 timeout 5 s                          | 55 s      | 98.3  | 98.86       | 0                                                       |
| D2 timeout 3 s                          | 70 s      | 99.24 | 99.81       | 0 lost, 5 false kills gained, 72 killed became timeouts |
| E `--git-diff-lines` (2 changed lines)  | 3 s       | 100   | 100         | n/a (2 mutants against 367)                             |
| F two shards in parallel, same host     | 225 s     | n/a   | n/a         | invalid (136 timeouts)                                  |
| F shards run alone: 75 s and 35 s       | 110 s sum | n/a   | n/a         | 0                                                       |

Coverage generation: 151 s (Small) against 359 s (Small and Large). Whole-tree mutant generation alone: 661 s.

## Not measured

- A whole-tree run (extrapolated, not run), and the whole-tree effect of B, C and D.
- Repeat runs per variant: one run each on a loaded host; differences of 20 to 30 percent are noise, only the large
  effects (C, E, the D 3 s inflation, the F contention) are clear.
- Whether a `core` suite would let a mutant on a line covered by both a Small and a Large test escape; `--git-diff-lines`
  on a multi-file diff or across renames; ParaTest-produced coverage; real multi-machine sharding; the Large tests' own
  wall time.
