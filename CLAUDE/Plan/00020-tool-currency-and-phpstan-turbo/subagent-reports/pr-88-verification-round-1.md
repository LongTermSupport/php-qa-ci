# PR #88 verification: PHPStan lanes, an analysis abandoned on internal errors is a crash (#82 part 1)

- Pull request: https://github.com/LongTermSupport/php-qa-ci/pull/88
- Branch: `bugfix/phpstan-internal-error-is-a-crash`, base `php8.5`
- Verified head: `3b655009aa7d8321cc66010b68f445174939d858` (merge of `origin/php8.5` `f324011` into fix `70b30e5`)
- Base read: `origin/php8.5` = `f324011b2921c074591b64cdd53d71df35fd0519`
- Verifier: fresh sub-agent, read-only towards the PR. Reproductions ran in a throwaway
  `git worktree add --detach` under `untracked/scratch/verify-88-87/` (since removed) and against
  the shipped `vendor-phar/phpstan.phar` (2.3.0). Fixtures and scripts kept in
  `untracked/scratch/verify-88-87/` (`fx/`, `run.php`, `run-all.bash`, `regex.php`).

## 1. CI

`gh pr checks 88`, on head `3b65500`. The CI run `37854684689` was triggered by `push`; QA ran
as `37854689920`.

- `Detect PHP Version`: pass (5s).
- `PHP QA (8.5)`: pass (26m2s).
- `QA Pipeline`: pass (2m24s).
- `Coverage Report`: skipped by its own condition. `qa.yml:317` has
  `if: github.event_name == 'pull_request'`, and this run's event is `push`. That is expected.

## 2. Scope

The diff is 8 files: `PhpstanCrash` + its test, the two lanes + their tests, `CHANGELOG.md`,
`docs/tools/phpstan.md`. It matches the description. No debug output, no secrets, no lock file, no
`ignoreErrors`/baseline/suppression added. The merge commit `3b65500` adds nothing of its own:
`gh pr diff 88` against `php8.5` shows only the PR's changes, and in `DeadCodeTool.php` the only
difference from `php8.5` is the `PhpstanCrash` import and the replacement of `exitCode > 1` by
`PhpstanCrash::reason()` (lines 93-96). The `DetectorUnpacker` changes from #83 are intact. `git merge-base --is-ancestor origin/php8.5 origin/bugfix/...` succeeds, so the branch is up to date
with the base; `mergeable: MERGEABLE`.

## 3. Project rules

- Defence Before Fix ordering: red `03d8f43` (tests only) comes before fix `70b30e5`, both
  reachable. **Reproduced**: at `03d8f43` in a throwaway worktree all four red tests fail on the
  outcome, `Failed` where `Crashed` was expected:

  - `PhpstanToolTest::anIncompleteResultIsACrashAndIsReRunWithDebug` (line 365);
  - `PhpstanToolTest::jsonModeIncompleteResultIsACrash` (line 445);
  - `PhpstanToolTest::anAgentModeIncompleteResultIsACrashNotAFindingsReport` (line 596);
  - `DeadCodeToolTest::anIncompleteResultIsACrashNotDeadCode` (line 217).

  At head `3b65500`: `PhpstanToolTest` 32/32, `DeadCodeToolTest` 9/9, `PhpstanCrashTest` 6/6 pass.

- Changelog: entry under `## Unreleased` → `### Fixed`, which is the right heading for a
  misreport fixed. The wording is accurate for the trigger it covers (see finding 1 for what it
  does not cover). The red commit carries `Changelog: none — tests only`, correct for `tests/`.

- Branch name `bugfix/...` is allowed.

- The DBF record is missing; see finding 1.

## 4. Correctness

### What was verified and holds

- **PHPStan's behaviour, from the shipped phar source**
  (`phar://…/vendor-phar/phpstan.phar/src/Command/AnalyseCommand.php`). Lines 342-351: when
  `$internalErrorsTuples` is non-empty the result is replaced by one holding only the internal
  errors. The exit code is the formatter's (`1`, since there are errors). The marker
  `'⚠️  Result is incomplete because of severe errors. ⚠️'` is written by
  `$errorOutput->writeLineFormatted()` at line 346, and `$errorOutput` is
  `$inceptionResult->errorOutput` (line 177), i.e. stderr. The PR's "lines 342-350" claim is right.

- **The marker reaches `ProcessResultDto::$output`.** `SymfonyProcessRunner::run()` appends every
  buffer, OUT and ERR, to `$captured`, and puts only OUT into `$stdout`. **Reproduced** against the
  real phar with a fixture rule that throws in `processNode()` (`fx/internal.neon`, loaded with
  `-a`), captured exactly as the runner does:

  | mode                  | exit | marker in `$stdout` | marker in `$output` | `PhpstanCrash::reason()` |
  | --------------------- | ---- | ------------------- | ------------------- | ------------------------ |
  | text                  | 1    | no                  | yes                 | `INCOMPLETE_REASON`      |
  | `--error-format=json` | 1    | no                  | yes                 | `INCOMPLETE_REASON`      |
  | text, `FORCE_COLOR=1` | 1    | no                  | yes (0 ESC bytes)   | `INCOMPLETE_REASON`      |

  In JSON mode the real output has no newline between the JSON and the marker
  (`…]}⚠️  Result is incomplete…`); the regex's leading `^.*` still matches it. The PR's tests
  always put a `\n` there, so this real shape is covered only by my reproduction.

- **ANSI.** The marker line carries no formatter tags, so even a decorated stream writes it
  without escape codes (`FORCE_COLOR=1` run above: 0 ESC bytes in the whole output).

- **Regex probes** (`regex.php`, all at exit 1):

  | input                                                                                       | result                   |
  | ------------------------------------------------------------------------------------------- | ------------------------ |
  | real line, LF / CRLF / no trailing newline / one space / progress bar then `\r` on the line | crash                    |
  | no emoji; `⚠` without U+FE0F; ANSI wrapping the line; `\e[0m` after the emoji               | verdict (false negative) |
  | finding quoting the phrase in quotes                                                        | verdict (correct)        |
  | finding whose message line ends with `…severe errors. ⚠️`                                   | crash (false positive)   |

  The false negatives cannot happen with the shipped 2.3.0 and the false positive needs a finding
  message ending in the exact phrase and emoji. None of these block; see note N1.

- **Every lane that runs phpstan.phar** uses `PhpstanCrash`. `grep` over `src/` and `bin/` finds
  phpstan.phar run only by `PhpstanTool` (three modes) and `DeadCodeTool`. `bin/phpstan-rule` runs
  it through `qa -t phpstan --json` (see finding 1(c)).

- **Docs.** `docs/tools/phpstan.md` text and `--json` paragraphs are updated and accurate; agent
  mode defers to `docs/agent-mode.md`, whose exit-3 / `crashed` definition already covers it.
  `docs/tools/deadCode.md` says nothing about exit classification, so nothing there went stale.

### Blocking findings

**1. The class is drawn at one trigger, the wider class is reproduced on the shipped phar, and
nothing records the bound (DBF 3.1.3 / 3.1.5; DefenceBeforeFix.md "Authority").** [reproduced]

#82 names the class itself: *"a lane infers its outcome from an exit code the tool shares between
'findings' and 'I could not analyse'"*. The PR handles one way PHPStan reaches exit 1 without a
verdict, the incomplete-result marker. `AnalyseCommand.php` has at least 15 other `return 1` /
`handleReturn(1, …)` paths that are not findings (lines 150-230, 254, 585, 595). An uncaught
exception from `analyse()` without `--debug` is rethrown (line 287), and Symfony's `Application`
turns it into exit 1. Three of these, run against the shipped phar and classified by the PR's own
`PhpstanCrash::reason()` (`run-all.bash`):

| fixture                                         | real exit | output                                                                                 | `reason()` |
| ----------------------------------------------- | --------- | -------------------------------------------------------------------------------------- | ---------- |
| `fx/badparam.neon` (unknown parameter)          | 1         | `Invalid configuration: Unexpected item 'parameters › notARealParameter'.`             | `NULL`     |
| `fx/badbootstrap.neon` (missing bootstrap file) | 1         | `In ResultCacheManager.php line 2357: Could not read file: …`                          | `NULL`     |
| rule class not autoloadable                     | 1         | `In Resolver.php line 114: Service 'rules.0': Class 'FxRules\ThrowingRule' not found.` | `NULL`     |

These were the results in both text and `--error-format=json`. So after this PR:

- (a) **text mode** `PhpstanTool::runText()` reports these as `PHPStan found errors` with the
  identifier trailer, and skips the `--debug` re-run;

- (b) **`--json` mode** `runJson()` writes an empty stdout and reports `PHPStan found errors`.
  Agent mode already handles this shape, because `PhpstanJsonParser` turns unreadable JSON into a
  crash; that is the shape the other modes lack;

- (c) **`DeadCodeTool`** prints its "Delete it, or wire the caller" guidance and `dead code found`
  over a configuration error. That is #82 part 1's symptom from a different trigger;

- (d) **`bin/phpstan-rule`**, the single-rule harness DefenceBeforeFix.md 3.3 relies on to
  "confirm the rule was loaded", ignores `qa`'s exit code by design (`bin/phpstan-rule` line 31:
  "the exit code is deliberately not consulted") and reads only `files.*.messages`. An abandoned
  analysis yields `{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: …"]}`
  (real output above). Fed that JSON, the harness answers:

  ```text
  $ printf '%s' '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: boom …"]}' | php bin/single-rule-report phpqaci.nestedTernary
  phpqaci.nestedTernary did not fire
  exit=0
  ```

  That is a false "did not fire" on an analysis that never ran the rule, the exact trigger this PR
  is about, through the `--json` mode it changed.

DBF 3.1.3 requires a remedy that catches only the originating instance to be widened, unless the
search found nothing wider. In that case it must record that, and name the next wider rule with the
reason it was not built. Leaving the wider class unhandled for any reason but an absent hazard is an
Owner decision. No record of the class, its bound, the two search techniques, the
detector-or-toolchain-gap choice (DBF 3.2: this is runtime classification, so a static detector
looks impractical, and that must itself be recorded as a toolchain gap) or the next wider rule
exists:

- not in the PR body;
- not in either commit message;
- not in #82's comments;
- not in Plan 00020: `git grep` of the plan folder on this branch and on `chore/plan-00020-status`
  finds #82 only as part 2, in PLAN.md (which holds the plan's decisions; there is no
  DECISIONS.md), the JOURNAL and the PR #83 reports.

The PR #83 round-2 report already raised this pattern for that PR's fixes (`pr-83-verification-r2.md`
note 02, "Neither fix records the class or builds a Detector").

To clear it, do either of the following:

- widen the fix:
  - a verdict at exit 1 requires positive evidence of findings. In `--json` mode that is a parseable
    PHPStan report on stdout, as agent mode already requires; in text mode and the dead-code lane it
    could be the table formatter's `Found N error` summary, or running PHPStan with
    `--error-format=json` internally;
  - anything else at exit 1 is a crash;
  - `bin/phpstan-rule` treats the lane's crash exit, or a non-empty top-level `errors`, as "no
    answer" (exit 2);
- or record the bound and the reason in the plan's DECISIONS and refer the unbuilt wider rule to
  the Owner. In that case the CHANGELOG and docs should not imply that every non-verdict exit 1 is
  now a crash.

### Notes (non-blocking)

- **N1. Nothing pins the marker to the shipped phar.** The fix depends on one upstream string,
  emoji included (`\.\s*⚠️\s*$`), and Plan 00020 is the plan that updates `phpstan.phar`
  automatically. If an update rewords or drops the emoji, every abandoned analysis silently
  becomes "findings" again and no test fails, since the tests feed a hand-typed copy. A Small test
  could read the line from `phar://vendor-phar/phpstan.phar/src/Command/AnalyseCommand.php` and
  assert `PhpstanCrash::reason()` classifies it. Alternatively, a Large test could run the
  throwing-rule fixture in `untracked/scratch/verify-88-87/fx/`. Matching the phrase without the
  trailing emoji would also make the classifier less brittle.
- **N2.** `PhpstanCrashTest`'s false-positive case `'a finding that quotes the words'` quotes only
  `'Result is incomplete'`, which the regex could never match. It does not probe the boundary the
  regex actually draws (the full phrase plus `. ⚠️` at end of line).
- **N3.** The PR's claim that `PhpstanCrash::reason()` classifies the real #82 battery log was not
  verified: that log is local to the author.

## 5. Mergeability

`mergeable: MERGEABLE`. The head contains the current base `f324011`, the PR carries only its own
three commits, and the merge introduces no unrelated change.

VERDICT: FAIL

Blocking:

1. `src/Pipeline/Lane/Phpstan/PhpstanCrash.php:33-41`: the class #82 names is drawn at the
   incomplete-result marker only. Reproduced on the shipped phar: invalid config, a missing
   bootstrap file and an unresolvable rule class all exit 1 and classify as a verdict. So
   `PhpstanTool` text and `--json` modes report them as findings, and `DeadCodeTool` prints
   delete-it guidance. `bin/phpstan-rule` (lines 31-36) answers "did not fire", exit 0, for an
   abandoned analysis. No DBF record bounds the class or refers the wider rule to the Owner.
