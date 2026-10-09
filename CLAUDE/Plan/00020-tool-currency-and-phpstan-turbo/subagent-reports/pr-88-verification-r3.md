# PR #88 verification, round 3: PHPStan exit 1 is findings only with evidence of findings (#82 part 1)

- Pull request: https://github.com/LongTermSupport/php-qa-ci/pull/88
- Branch: `bugfix/phpstan-internal-error-is-a-crash`, base `php8.5`
- Verified head: `967dd782564817747d0d2c0a753c544186fef8d4` (equal to `origin/bugfix/...`)
- Base read: `origin/php8.5` = `85336c3482d521877e1cf372db5eafd8c0f0d3c0` (an ancestor of the head)
- Round-3 commits: red `248f58c`, fix `493376b`, then the round-2 report (`967dd78`)
- Verifier: a fresh sub-agent, read-only towards the PR. Reproductions ran in a throwaway
  `git worktree add --detach untracked/worktrees/verify-88-r3` with its own `composer install`.
  It was moved from `248f58c` to `967dd78` and has since been removed. Scratch edits made inside
  it (a test rule, a 1001-error file, `errorFormat: raw`) were reverted before removal. The
  fixtures came from `untracked/scratch/v88r2/`, and the phar sources were read from
  `untracked/scratch/v88r2/ex/`.

## 1. CI

`gh pr checks 88` on `967dd78`:

- `Detect PHP Version`: pass.
- `QA Pipeline`: pass (2m48s).
- `PHP QA (8.5)`: **pending** (run `37865636260`), checked twice during this review.

A running check is not a pass, so the merge has to wait for `PHP QA (8.5)` to conclude success.
`mergeStateStatus` is `UNSTABLE`, which matches. The PR body records a green local
`QA_READONLY=1 CI=true bin/qa` at `967dd78`.

## 2. Scope

Round 3 touches `PhpstanCrash`, `PhpstanTool`, `DeadCodeTool`, `SingleRuleReport`,
`bin/phpstan-rule`, `bin/single-rule-report`, `docs/tools/phpstan.md` and `CHANGELOG.md`. It also
changes their tests, adds the fixture `tests/assets/phpstanOutcomes/ExitingRule.php`, and commits
the round-2 report. This matches the PR body.

The added lines contain no debug output, no secrets, no lock-file edit, and no `ignoreErrors`,
baseline or inline suppression. A grep for these over `origin/php8.5..967dd78` hits only prose in
the committed reports. The PR is `MERGEABLE`, and the base is an ancestor of the head.

## 3. Project rules

- **Defence Before Fix ordering, reproduced.** At red `248f58c`, running
  `bin/phpunit -c qaConfig/phpunit.xml --no-coverage` against the pre-fix `src/` gave:

  | Test                                    | Result at the red commit                                                                                                                                                                                                                      |
  | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
  | `PhpstanCrashTest`                      | **Behavioural failures** on the new data cases `more findings than the table shows` (`Found 1000+ errors`) and `findings and warnings`: `reason()` returned `NO_REPORT_REASON` where null was expected. Also 2 errors from undefined symbols. |
  | `PhpstanToolTest`                       | 6 errors from undefined symbols (`TABLE_FORMAT`, `noVerdictLine`).                                                                                                                                                                            |
  | `DeadCodeToolTest`                      | 1 error from an undefined symbol (`TABLE_FORMAT`).                                                                                                                                                                                            |
  | `SingleRuleReportTest`                  | 1 error from an undefined symbol (`noVerdictLine`).                                                                                                                                                                                           |
  | `PhpstanOutcomesFromTheShippedPharTest` | 2 errors from an undefined symbol (`TABLE_FORMAT`). `aDeadWorkerIsAnAbandonedAnalysis` passes at the red commit, as it should: it pins the phar's behaviour (a dead worker gives `INCOMPLETE_REASON` from `jsonReason`), not the new code.    |

  Most of the B2 red fails on missing symbols rather than on outcomes. That is the same
  convention the earlier rounds used. The assertions behind those symbols are behavioural (the lane
  prints the line, `main()` returns 2), so they would also fail against a stub.

  At head `967dd78` the same five classes pass: 20, 34, 10, 9 and 6 tests.

- **The two red-test adjustments do not weaken the red.**

  - The deleted `theTableFormatIsNamedForTheLanesThatReadIt` asserted only that a constant equals
    its literal. `aProjectErrorFormatCannotHideTheVerdictFromTheLanes` now pins the value by
    behaviour: if `TABLE_FORMAT` named any format other than `table`, the "named" run on the
    shipped phar would print no summary line and `reason()` would not be null. The lane tests
    assert that the constant is in the argument list.
  - The repeated literal in `SingleRuleReportTest` becoming a constant is cosmetic.

- **Changelog.** `## Unreleased` → `### Fixed` now mentions `--error-format=table` and the
  dead-worker case. The red commit carries `Changelog: none — red tests only`.

- **Branch name.** `bugfix/` is allowed.

## 4. Correctness

### B1 (table summary: 1000+, warnings tail, project `errorFormat`): **closed** [reproduced]

- **The regex against the shipped phar's `TableErrorFormatter`, branch by branch** (2.3.0,
  `ex/src/Command/ErrorFormatter/TableErrorFormatter.php:163-183`):

  - **Past the budget:** `error('Found 1000+ errors')`, with the budget the constant 1000. `[1-9]\d*\+?` matches it.
  - **Within the budget, with errors:** `error('Found %d error(s)' [+ ' and %d warning(s)'])`. The optional
    `(?: and [1-9]\d* warnings?)?` matches it.
  - **No errors, only warnings:** `warning(...)` with exit 0, so the lane never classifies it.
  - **Nothing at all:** `success('No errors')` with exit 0.

  SymfonyStyle's block padding is absorbed by `\s*$`. The budget branch never appends warnings, so
  no combined form is missed.

- **The format is forced on every path that reads table output.** It is passed in `PhpstanTool::runText`
  (`src/Pipeline/Lane/PhpstanTool.php:244`) and `DeadCodeTool` (`src/Pipeline/Lane/DeadCodeTool.php:80`).
  `PhpstanCrash::reason()` has no other caller. The `--debug -v` re-run is not classified, so it
  does not need the format. JSON and agent mode already pass `--error-format=json`. Nothing was
  missed.

- **End to end through `bin/qa`.** In the throwaway worktree, `errorFormat: raw` was added to
  `qaConfig/phpstan.neon` and a 1001-undefined-function file added to `src/`. Then
  `CI=true bin/qa -t stan -p src/VerifyMany.php` printed `[ERROR] Found 1000+ errors`, exited 1,
  and ended with the `phpqaci.phpstan` identifier trailer. There was no `Crashed` banner and no
  `--debug` re-run. So both B1 shapes are handled together, through the real lane.

- **GitHub Actions annotations are preserved.** `TableErrorFormatter::formatErrors()` calls
  `CiDetectedErrorFormatter` first, unconditionally (`TableErrorFormatter.php:78`), and that
  delegates to `GithubErrorFormatter` under GitHub Actions. Naming `table` explicitly is therefore
  identical to the default. Reproduced with `GITHUB_ACTIONS=true CI=true bin/qa -t stan -p <one-error file>`:
  `::error file=...,line=4,col=0::Function undefined_one not found.`, then `[ERROR] Found 1 error`,
  then the identifier trailer.

### B2 (`bin/phpstan-rule` answering for a dead parallel worker): **closed** [reproduced end to end]

- **The line reaches qa's stderr in `--json` mode.** `QaApplication::run()`
  (`src/Pipeline/Cli/QaApplication.php:88-92`) routes the decoration output to
  `StreamOutput(STDERR)` when `--json` is given. `ToolContext::writeln()` writes to that output,
  and `--json` printing is unchanged otherwise. `writeln` does not wrap. The reasons are fixed
  constants with no console tags, so the formatter cannot alter them. Each crash path in `runJson`
  prints the line, and `PhpstanToolTest` asserts it for exit 255, `NO_REPORT_REASON` and the
  incomplete result.

- **End to end.** In the throwaway worktree a rule that calls `exit(3)` in its worker was
  registered in `qaConfig/phpstan.neon`. Then
  `CI=true bin/phpstan-rule phpqaci.nestedTernary src/PHPStan/SingleRuleReport.php` printed
  `PHPStan abandoned the analysis on internal errors (result incomplete), so this run cannot say whether the rule fired`
  and exited **2**.

  With the rule removed, the same command answered `phpqaci.nestedTernary did not fire` with
  exit 0. A file containing a nested ternary gave `FIRED (1)` with its location and exit 1. All
  three exit codes are correct.

- **False positives.** `noVerdictIn()` needs a line in qa's stderr that *begins* with
  `PHPStan reached no verdict: `. In `--json` mode PHPStan's stdout (where the analysed source
  could appear) goes to the real stdout, not into the saved stderr. Analysed source text is
  therefore never scanned. Only qa's own decoration and a project's pre-hook could print that
  prefix. A pre-hook doing so is not a realistic hazard.

- **The `bin/phpstan-rule` change.** The script now runs
  `rc=0; php ... "$qaLog" <<< "$json" || rc=$?; exit "$rc"`, which is correct under
  `set -euo pipefail`: the `||` disables `errexit` for that command and keeps its status. Exit then
  fires the `EXIT` trap, so `$qaLog` is now removed. Under the old `exec`, the trap never ran and
  the temp file leaked. ShellCheck at `warning` is clean on the file.

### Notes (non-blocking)

- **N1.** No automated test covers the shell wiring: `bin/phpstan-rule` passing `$qaLog`, and
  `bin/single-rule-report` reading `argv[2]`. If the argument were dropped, every test would still
  pass. The end-to-end run above shows the wiring works today. A Large test that drives
  `bin/single-rule-report` as a process, given a log with the line, would pin it.
- **N2.** `bin/single-rule-report` reads `argv[2]` with `\Safe\file_get_contents`, so an
  unreadable path is an uncaught exception (exit 255), not the documented exit 2.
  `bin/phpstan-rule` always passes a file it has just created, so this only reaches someone who
  calls the PHP half directly.
- **N3.** Forcing `--error-format=table` means a consumer whose `parameters.errorFormat` was, say,
  `checkstyle` no longer gets that format in a text-mode qa run. The CHANGELOG states this under
  `Fixed`. The qa output was never machine-parseable, because it is interleaved with the
  pipeline's decoration. GitHub annotations are unaffected (see B1).
- **N4.** `docs/tools/deadCode.md` does not mention that the dead-code lane also forces the table
  format, or how it tells findings from a crash. `docs/tools/phpstan.md` does describe this for
  the PHPStan lane.
- **N5.** Round-2 notes N1 (stdout noise before the JSON in `--json` mode) and N2 (the over-long
  docblock line at `src/Pipeline/Lane/PhpstanTool.php:28`) still stand. They are cosmetic or
  consistent with agent mode.

## 5. Mergeability

`mergeable: MERGEABLE`, `reviewDecision` empty (no approval required). The head contains the
current base `85336c3`, and the branch carries only its own commits plus merges of the base.
`mergeStateStatus: UNSTABLE` only because `PHP QA (8.5)` is still running.

Round 2's two blocking findings are closed, both reproduced end to end on the shipped phar
through `bin/qa` and `bin/phpstan-rule`. No new defect was found. The verdict holds on the
condition that `PHP QA (8.5)` on `967dd78` concludes success before the merge.

PASS
