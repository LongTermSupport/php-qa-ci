# PR #88 verification, round 2: PHPStan exit 1 is findings only with evidence of findings (#82 part 1)

- Pull request: https://github.com/LongTermSupport/php-qa-ci/pull/88
- Branch: `bugfix/phpstan-internal-error-is-a-crash`, base `php8.5`
- Verified head: `3f6e344aaf93f4014505eb5fe5c51efde0394552` (equal to `origin/bugfix/...`)
- Base read: `origin/php8.5` = `85336c3482d521877e1cf372db5eafd8c0f0d3c0`
- Round-2 commits: red `5874854`, fix `93a99fb`, then the round-1 report (`addc69d`, `cac5057`) and a
  merge of `php8.5` (`3f6e344`)
- Verifier: fresh sub-agent, read-only towards the PR. Reproductions ran in a throwaway
  `git worktree add --detach untracked/worktrees/verify-88-r2-red` (since removed) and against the
  shipped `vendor-phar/phpstan.phar` (2.3.0). Fixtures and scripts are in `untracked/scratch/v88r2/`
  (`classify.php`, `report.php`, `fx/src/One.php`, `fx/src/Many.php`, `fx/ExitingRule.php`,
  `base.neon`, `raw.neon`, `exiting.neon`). The phar's `src/Command/` sources were extracted to
  `untracked/scratch/v88r2/ex/` to read them.

## 1. CI

`gh pr checks 88` on head `3f6e344`:

- `Detect PHP Version`: pass.
- `QA Pipeline`: pass (2m12s).
- `PHP QA (8.5)`: **pending** (run `37861461752`). A running check is not a pass; the merge would
  have to wait for it regardless of this verdict.

`mergeStateStatus` is `UNSTABLE`, which matches the pending check.

## 2. Scope

16 files: `PhpstanCrash` and its test, `PhpstanTool` / `DeadCodeTool` and their tests,
`SingleRuleReport` and its test, the two script headers, the Large test with its fixtures under
`tests/assets/phpstanOutcomes/`, `docs/tools/phpstan.md`, `CHANGELOG.md`, and the round-1 report
under the plan's `subagent-reports/`. This matches the body. No debug output, no secrets, no lock
file, and no `ignoreErrors`, baseline or inline suppression was added. The `php8.5` merge brings in
plan documents only. The base is an ancestor of the head, and the PR is `MERGEABLE`.

## 3. Project rules

- **Defence Before Fix ordering, reproduced.** At red `5874854` (tests only, with the pre-fix
  `src/`), run with `bin/phpunit -c qaConfig/phpunit.xml --no-coverage`:

  - `PhpstanCrashTest`: 10 errors (undefined `jsonReason()` / `NO_REPORT_REASON`).
  - `PhpstanToolTest`: 2 assertion failures, `aConfigurationErrorIsACrashNotFindings` and
    `jsonModeAConfigurationErrorIsACrash`.
  - `DeadCodeToolTest`: 1 failure, `aConfigurationErrorIsACrashNotDeadCode`.
  - `SingleRuleReportTest`: 1 failure, `anAbandonedAnalysisIsNotAnAnswer`.
  - `PhpstanOutcomesFromTheShippedPharTest`: 3 errors (undefined API).

  The behavioural tests fail on the outcome, not only on missing symbols. At head `3f6e344` all of
  them pass: 17, 34, 10, 7 and 3 tests. `bin/qa -t stan` is clean on `src/Pipeline/Lane/Phpstan`
  and on `src/PHPStan/SingleRuleReport.php`.

- **Changelog.** The `## Unreleased` → `### Fixed` entry is present. The red commit carries
  `Changelog: none — red tests only`.

- **Branch name.** `bugfix/` is allowed.

- **DBF record.** Round 1's alternative was to record a bound. Round 2 widened the remedy to the
  class "an exit 1 read as findings without evidence of findings" instead. That is the right choice,
  provided the evidence test is correct. Section 4 shows two places where it is not.

## 4. Correctness

### Round-1 blocking finding: status by sub-item

| Round-1 item                                                   | Round 2                                                                                               | Status             |
| -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------ |
| (a) text mode: config error / bootstrap / missing rule class   | `reason()` requires the `[ERROR] Found N error(s)` line                                               | closed, with B1    |
| (b) `--json` mode, same triggers                               | `jsonReason()` requires stdout JSON with a positive total                                             | closed             |
| (c) `DeadCodeTool` delete-it guidance over a config error      | goes through `reason()`                                                                               | closed, with B1    |
| (d) `bin/phpstan-rule` "did not fire" on an abandoned analysis | `SingleRuleReport` refuses `phpstan.internal` messages and top-level errors prefixed `Internal error` | **partly**, see B2 |
| DBF record or widening                                         | widened                                                                                               | closed             |

### What was verified and holds

- **The Large test really runs the shipped phar.** `PhpstanOutcomesFromTheShippedPharTest` runs
  `vendor-phar/phpstan.phar` through `PhpInvoker` and `SymfonyProcessRunner`, which are the lanes'
  own path, and it is in the `tests/Large` suite that `qaConfig/phpunit.xml` runs. It covers
  findings, a config error and a throwing rule, in table and JSON formats. This closes round-1 note
  N1 for those three shapes.
- **Table output in other environments, reproduced.** With `GITHUB_ACTIONS=true`, PHPStan's
  `CiDetectedErrorFormatter` prints `::error` annotations, then the table, then
  `[ERROR] Found 1 error`, and `reason()` returns null (a verdict). With `FORCE_COLOR=1` there are
  no ANSI codes, and `reason()` again returns null. `SymfonyProcessRunner` sets `setPty(false)`, so
  the output is never decorated.
- **Warnings.** The formatter can print `Found N errors and M warnings`, which the regex would
  reject. In 2.3.0 every `AnalysisResult` is constructed with `warnings: []`
  (`AnalyseApplication.php:106`, `AnalyseCommand.php:343`), so this is unreachable today. It is a
  note, not a finding.
- **JSON mode.** `--error-format=json` on the command line overrides any config `errorFormat`. The
  JSON formatter has no error budget. `totals.errors` counts non-file errors, so a run with only
  non-file errors (for example unmatched ignore patterns) counts as findings. An abandoned run is
  caught by the marker first.
- **`SingleRuleReport` on a throwing rule.** A rule that throws produces a `phpstan.internal`
  message, or an `Internal error: …` top-level entry when the error carries a stack trace
  (`AnalyseCommand.php:296-306`). Both are refused, and the Large test asserts this on the real
  phar.

### Blocking findings

**B1. A table-format findings run reads as a crash in two realistic shapes, a regression from
before the PR.** [reproduced]

`PhpstanCrash::FINDINGS_SUMMARY` (`src/Pipeline/Lane/Phpstan/PhpstanCrash.php:35`) is
`/^\s*\[ERROR\] Found [1-9]\d* errors?\s*$/m`. The shipped phar's table formatter does not always
print that line for a run with findings:

1. **More than 1000 file errors.** `TableErrorFormatter` sets `errorsBudget = 1000` unless
   `PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS` is set to something other than `0`. The
   lane never sets that variable. Above the budget it prints `[ERROR] Found 1000+ errors`, and the
   `+` defeats the regex. Reproduced with `fx/src/Many.php` (1001 undefined-function calls, level 0)
   through the lanes' runner:

   ```text
   exit=1
   [ERROR] Found 1000+ errors
   reason()='PHPStan exited 1 without reporting any findings (an error before or outside the analysis; see its output)'
   ```

   With `PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1` the same run prints `Found 1001 errors`, and `reason()` returns null.

2. **A project `parameters.errorFormat`.** PHPStan 2.3 reads `errorFormat` from the config when
   `--error-format` is not passed (`AnalyseCommand.php:178-186`; `conf/config.neon:258`). Text mode
   and `DeadCodeTool` do not pass `--error-format`, and both include the project's `phpstan.neon`.
   A consumer with, say, `errorFormat: raw` gets no summary line at all. Reproduced with `raw.neon`
   and one finding: exit 1, `reason()` = `NO_REPORT_REASON`.

In both shapes, text mode now reports `PHPStan Crashed!!`, re-runs the whole analysis with
`--debug -v`, and returns `crashed` instead of findings with the identifier trailer. The dead-code
lane reports a crash instead of dead code. Before the PR both were correctly findings. The first
shape is the ordinary state of a legacy codebase adopting php-qa-ci, or of a level bump. That
codebase also pays for a full second analysis with `--debug` (no parallelism) on every run. This
repository has neither shape, so nothing here exercises them, and the Large test covers only a
one-finding run.

The PR's "fails safe" argument holds for a reworded summary line, but these shapes are not a
rewording. They are current, documented phar behaviour.

To clear it, any one of these would do:

- pass `--error-format=table` explicitly in text mode and in the dead-code lane, and set
  `PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1`, or accept `Found \d+\+ errors` (and the
  `and N warnings` tail);
- or decide text mode and dead code from an internal `--error-format=json` run.

Then add the 1000+ and `errorFormat` shapes to the Large test.

**B2. `bin/phpstan-rule` still answers "did not fire" (exit 0) for an analysis abandoned on a dead
parallel worker.** [reproduced]

`SingleRuleReport::fromJson()` (`src/PHPStan/SingleRuleReport.php:50-54`) refuses a top-level
error only when it begins with `Internal error`. PHPStan adds that prefix only when the internal
error carries a stack trace (`AnalyseCommand.php:296`). The parallel-worker internal errors carry
none (`ParallelAnalyser.php`):

- `Child process error (exit code %d): …`
- `Child process was killed by signal …` (OOM)
- `Child process ended unexpectedly: …`
- `Child process error: <memory limit> …`
- `Some parallel worker jobs have not finished.`

These are the segfault and OOM cases the project's segfault policy cares about. Reproduced with a
rule that calls `exit(3)` in a worker (`fx/ExitingRule.php`, `exiting.neon`), fed through
`report.php` exactly as `bin/phpstan-rule` feeds `bin/single-rule-report`:

```text
exit=1
stdout={"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Child process error (exit code 3):  while running parallel worker"]}
jsonReason()='PHPStan abandoned the analysis on internal errors (result incomplete)'
SingleRuleReport::main exit=function.notFound did not fire
0
```

The lane itself classifies this run as abandoned (`INCOMPLETE_REASON`), but the harness answers it.
The new header in `bin/phpstan-rule` (lines 9-10), `bin/single-rule-report`, and
`docs/tools/phpstan.md` ("2 when … it abandoned the analysis on internal errors") all promise
exit 2 for this case, as does the CHANGELOG ("it now exits 2"). So round-1 item (d) is closed only
for the throwing-rule trigger. Round 1 suggested "a non-empty top-level `errors`" or the lane's
crash exit. Either closes this. The harness could also run the classification itself, by passing
`qa`'s exit code or the stderr marker through. If only the top-level-errors route is taken, note
that it would also refuse legitimate non-file findings such as unmatched ignore patterns. That is
arguably correct for a harness asked about a single rule, but it should be decided rather than
incidental.

### Notes (non-blocking)

- **N1.** In `--json` mode, anything a bootstrap file or a PHP notice writes to stdout before the
  JSON now makes a findings run a crash, because `json_validate` fails. Agent mode already behaved
  this way, so this is consistent, but it is a change for `--json` users and is not mentioned in
  the docs.
- **N2.** The `PhpstanTool` class docblock line added at `src/Pipeline/Lane/PhpstanTool.php:28` is
  over-long compared with its neighbours. This is cosmetic.
- **N3.** Round-1 notes N2 and N3 were not re-checked beyond what is above.

## 5. Mergeability

`mergeable: MERGEABLE`. The head contains the current base `85336c3`. `mergeStateStatus: UNSTABLE`
because `PHP QA (8.5)` is still running.

VERDICT: FAIL

Blocking:

1. `src/Pipeline/Lane/Phpstan/PhpstanCrash.php:35`: the table-format findings evidence misses
   `[ERROR] Found 1000+ errors`, which the shipped phar prints for any run with more than 1000 file
   errors because the error budget is on by default. It also misses every run under a project
   `parameters.errorFormat`, since text mode and `DeadCodeTool` do not pass `--error-format`. Both
   are reproduced on the shipped phar: genuine findings are reported as a crash with a `--debug`
   re-run, which is a regression from the pre-PR behaviour.
2. `src/PHPStan/SingleRuleReport.php:50-54`: an analysis abandoned because a parallel worker died
   (`Child process error (exit code N)`, killed by signal or OOM, ended unexpectedly) has no
   `Internal error` prefix. `bin/phpstan-rule` therefore still answers "did not fire" with exit 0,
   which contradicts its header, `docs/tools/phpstan.md` and the CHANGELOG. Reproduced on the
   shipped phar.

(Also: `PHP QA (8.5)` was still pending on `3f6e344` when this was read.)

FAIL
