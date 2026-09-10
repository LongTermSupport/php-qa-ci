# Lanes ported: phpunit and infection

Subagent report for Plan 00003, Phase "port the Bash lanes to PHP". The two
heaviest lanes, `includes/generic/phpunit.inc.bash` and
`includes/generic/infection.inc.bash`, now have PHP tool classes. The Bash
fragments are untouched (they leave in Phase 5). Nothing is committed and
`ShippedTools` / the identifier index are NOT wired (coordinator's job).

## Classes and identifiers

| Lane      | Tool class                                  | Identifier            | Pure helpers                                                                                                    |
| --------- | ------------------------------------------- | --------------------- | --------------------------------------------------------------------------------------------------------------- |
| phpunit   | `LTS\PHPQA\Pipeline\Lane\PhpunitTool`       | `phpqaci.phpunit`     | `Lane\Phpunit\PhpunitArguments`                                                                                 |
| infection | `LTS\PHPQA\Pipeline\Lane\InfectionTool`     | `phpqaci.infection`   | `Lane\Infection\InfectionArguments`, `Lane\Infection\InfectionDiffFilter`, `Lane\Infection\Dto\InfectionDiffFilterDto` |

Files (all new, absolute):

- `/workspace/src/Pipeline/Lane/PhpunitTool.php`
- `/workspace/src/Pipeline/Lane/Phpunit/PhpunitArguments.php`
- `/workspace/src/Pipeline/Lane/InfectionTool.php`
- `/workspace/src/Pipeline/Lane/Infection/InfectionArguments.php`
- `/workspace/src/Pipeline/Lane/Infection/InfectionDiffFilter.php`
- `/workspace/src/Pipeline/Lane/Infection/Dto/InfectionDiffFilterDto.php`
- `/workspace/tests/Small/Pipeline/Lane/PhpunitToolTest.php` (13 tests)
- `/workspace/tests/Small/Pipeline/Lane/Phpunit/PhpunitArgumentsTest.php` (7 tests)
- `/workspace/tests/Small/Pipeline/Lane/InfectionToolTest.php` (14 tests)
- `/workspace/tests/Small/Pipeline/Lane/Infection/InfectionArgumentsTest.php` (5 tests)
- `/workspace/tests/Small/Pipeline/Lane/Infection/InfectionDiffFilterTest.php` (4 tests)

Docs: a "How the lane runs" section appended to `docs/tools/phpunit.md`
(before the "Infection" cross-reference) and to the end of `docs/tools/infection.md`.

## What the phpunit lane does (mirrors the fragment)

1. Seeds `<testsDir>/bootstrap.php` with the placeholder (same text as the heredoc) and prints the two lines.
2. Probes `<binDir>/phpunit --version` (streamOutput false) and parses the major. An unparseable probe is `crashed` (the fragment would have died on an empty arithmetic compare).
3. Uses `<binDir>/paratest` with `--phpunit <binDir>/phpunit` when the file exists; prints the two lines.
4. argv, in the fragment's order: paratest delegate, `-c <configPath('phpunit.xml')>`, `--strict-global-state --fail-on-risky --fail-on-warning --log-junit <varDir>/phpunit_logs/phpunit.junit.xml`, the nine `--display-*`/`--colors=always` flags for major >= 10, the MODE flags (iterative > no-coverage > coverage+CI (nothing) > coverage+interactive), then `pathsToCheck` only when `specifiedPath` is set.
5. Env: `phpUnitQuickTests=1/0`, `XDEBUG_MODE=coverage` (coverage run via `withXdebug`) or `off` (via `withoutXdebug`).
6. Captured output written to `phpunit_logs/phpunit.log`; no-tests guard (junit missing or `<testsuites/>`) prints the message and forces the exit code to 1, exactly as the fragment did (so a crash with no tests is `failed`, not `crashed`).
7. Both logs archived through `$context->logs->archive(...)` (path-specific when `-p`); the last ANSI-stripped `Tests: … Assertions: …` line is printed as `Result: …`.
8. Exit 0 passed; 1 or 2 failed (identifier trailer); > 2 prints the crash note, re-runs `<binDir>/phpunit <testsDir> --debug` without Xdebug (env `qaQuickTests`) and returns `crashed`.

## What the infection lane does (mirrors the fragment)

1. `xdebugEnabled` false → `skipped` with the fragment's message.
2. Diff mode: `git status --porcelain -- <srcDir> <testsDir>` (cwd projectRoot, quiet). Dirty → the REFUSED text and `failed`; git failure → `failed`.
3. Coverage reuse (full run and non-empty `<varDir>/phpunit_logs/coverage-xml`) vs generation (single-tool run or nothing on disk: the coverage dir is removed, `withXdebug(<binDir>/phpunit, -c cfg --coverage-xml dir --log-junit junit, XDEBUG_MODE=coverage)`; failure → message + `failed`).
4. Diff mode: `git --no-pager diff <base>...HEAD --diff-filter=AM --name-only --relative -- <srcDir>`; PHP files only, absolutised against projectRoot; empty → `skipped`; git failure → both guidance lines and `failed`.
5. The 100%-floor advisory verbatim when in force (diff floor in diff mode, either full floor otherwise).
6. `<varDir>/infection/*` cleared with `\Safe\unlink`/`rmdir` (dir kept).
7. Phar via `$context->processes->run($context->php->specWithoutXdebug(<pharDir>/infection.phar, args, projectRoot, [], true, lowPriority: true))`.
8. argv: `[--only-covered] --coverage=<varDir>/phpunit_logs --skip-initial-tests --threads=N --configuration=<configPath('infection.json')>` then full `--min-msi --min-covered-msi --log-verbosity=all` or diff `--min-covered-msi=<diffCoveredMsi>` + positional paths LAST.
9. Non-zero → identifier trailer, `failed`. The runner owns retries.

## InfectionDiffModeTest cases ported

| Large test case                                             | PHP unit test                                                                                    |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| testFullModeBuildsTheHistoricFloorInvocation                | `InfectionArgumentsTest::fullModeBuildsTheHistoricFloorInvocation`                               |
| testDiffModeScopesToChangedFilesAndEnforcesNoNewEscapes     | `InfectionArgumentsTest::diffModeScopesToChangedFilesAndEnforcesNoNewEscapes`                    |
| testDiffCoveredMsiFloorIsOverridableForEquivalentMutants    | `InfectionArgumentsTest::diffCoveredMsiFloorIsOverridableForEquivalentMutants` + `InfectionToolTest::anOverriddenDiffFloorReachesInfectionAndSilencesTheAdvisory` |
| testDiffModeRefusesADirtyWorkingTree                        | `InfectionToolTest::diffModeRefusesADirtyWorkingTree`                                            |
| testDiffModeAcceptsACleanTree                               | `InfectionToolTest::diffModeAcceptsACleanTreeAndScopesToTheCommittedChange`                      |
| testDiffFilterIsComputedFromCommittedHistoryNotTheWorkingTree | `InfectionDiffFilterTest::theGitDiffIsAThreeDotDiffOfCommittedHistoryRelativeToTheCwd` + `theFilterIsExactlyTheCommittedChangeAbsolutisedAgainstTheCwd`, and the exact git argv assertion in `InfectionToolTest` |

The Large test drove real git; the unit tests pin the exact `git` argv and the
parsing instead (no subprocess), which is the contract the fake-runner
architecture calls for. The Large test itself is left in place for Phase 5.

## Not ported verbatim, and why

- **Interactive retry loops** (`tryAgainOrAbort`) in both fragments: dropped by design; `failed` is retried by the runner.
- **`set -x` tracing** around the phpunit invocation: not reproduced (the runner logs the command line).
- **Xdebug-enabled binary choice in iterative mode**: the fragment keyed the binary purely on `phpUnitCoverage`, and so does the port; iterative mode only adds `--no-coverage`. Same behaviour.
- **`XDEBUG_MODE=coverage` on the phpunit run**: the fragment relied on the Xdebug ini already being loaded; the port sets the env explicitly as the coordinator specified. Strictly additive.
- **Infection's `renice`/`oom_score_adj` subshell**: expressed as `lowPriority: true` on the spec (the runner prefixes `nice -n 19`); no OOM-score adjustment is made, as the process contract has no field for it.
- **Version probe failure**: the fragment would have crashed on a bad arithmetic compare; the port returns `crashed` with a message.

## Verification

```
composer dump-autoload -q && bin/phpunit -c qaConfig/phpunit.xml --no-coverage \
  tests/Small/Pipeline/Lane/PhpunitToolTest.php tests/Small/Pipeline/Lane/Phpunit \
  tests/Small/Pipeline/Lane/InfectionToolTest.php tests/Small/Pipeline/Lane/Infection
OK (43 tests, 156 assertions)
```

`CI=true QA_READONLY=1 bin/qa -t stan -p <path>` reports `[OK] No errors` for
each of the four source paths and four test paths. `-t fixer` dry-run is clean
on all eight paths (three cosmetic fixes were applied by the fixer itself and
one docblock summary it mangled was reworded). `-t markdown` passes.
Logs are under `untracked/scratch/`.
