# Design revision report: Plan 00026

Scope: revise [DESIGN.md](../DESIGN.md) and [PLAN.md](../PLAN.md) for the independent review in
[261030-design-review-fresh-reviewer.md](./261030-design-review-fresh-reviewer.md).

## Findings from Infection 0.35.8 source (read statically; no PHP binary available)

- `--filter` is a substring match against the real path: `Source/Collector/BasicSourceCollector.php:159-168` feeds the
  values to `FileSystem/Finder/Iterator/RealPathFilterIterator.php`, whose `toRegex()` (:167-172) builds
  `'/' . preg_quote($value, '/') . '/'` unless the value is already a regex. Absolute paths from one project root cannot
  contain one another, so they are safe; partial or relative values are not.
- Several values: `Configuration/SourceFilter/PlainFilter.php:80-88` splits on commas, trims, drops blanks (and a part
  equal to `0`). Alternatives semantics come from Symfony's iterator, outside the fetched tree: still to prove.
- `--filter` is deprecated since 0.34.0 (`Command/Option/SourceFilterOptions.php:131-137`); positional paths become the
  same `PlainFilter` (`PositionalPathsFilter.php:38-53`). `--filter` with a git option, a positional path with either,
  are refused by assertions (`SourceFilterOptions.php:251-277`). `--only-covering-test-cases` is orthogonal.
- Zero files matched: `NoSourceFound` flagged filtered (`Source/Exception/NoSourceFound.php:297-308`) becomes success
  and exit 0 (`Command/RunCommand.php:398-402`), raised before the `finally` in `Engine.php:93-115`, so probably no
  log. Matched files without covering tests yield no mutants silently (`Mutation/FileMutationGenerator.php:88-90`);
  whether a zero-count log is then written is unproven.

## Changes

- MAJOR 1: the join recomputes the plan from `index.xml` (small separate `infection-plan-input` artifact), compares each
  shard's recorded file list, checks every mutant's source file against its shard's list and rejects duplicate mutants.
- MAJOR 2: shard artifacts keep one name per index with `overwrite: true`; the join therefore sees exactly one upload
  per index, and commit, hash and plan checks reject a stale one.
- Minors: empty-shard handling (synthetic log, planner refuses N above the file count); the merger fails on an unknown
  log key; `bin/infection-plan` specified (new section 5a); weights limited to the mutation scope; distinct handoff
  artifact names and job name; uploads exclude `tmp/`; manual dispatch stays unsharded; shards use positional paths;
  argv figure clarified; PLAN.md wording fixed and the task list merged into PLAN.md, tests and detectors first.

## Unresolved (Task 2.0c, to prove with a real run)

Positional against `--filter` equivalence; multi-value alternatives; the zero-match and zero-mutant log and exit
behaviour; the real log key set; the floor comparison rounding; coverage artifact validity at the same path in a second
job.
