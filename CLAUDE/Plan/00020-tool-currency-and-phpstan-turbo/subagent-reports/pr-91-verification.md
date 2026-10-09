# PR #91 verification — Plan 00020: the PHPStan lane says whether Turbo is running

- **Verdict: PASS WITH NOTES**
- **Head verified:** `ace4dc995f3e20b74b413ea7d421929da0d32a24` (branch `feature/plan-00020-turbo-status`)
- **Base:** `php8.5`. `origin/php8.5` is an ancestor of the head. `mergeable=MERGEABLE`, `mergeStateStatus=CLEAN` at the end of verification. No PR comments.
- **Procedure:** CLAUDE/pr-verification.md. Read through `gh` and `git diff origin/php8.5...origin/feature/plan-00020-turbo-status`. Reproduced in a throwaway detached worktree `untracked/worktrees/verify-91`, which has since been removed. The author's worktree was not touched.

## 1. CI (head ace4dc9)

| Check              | Workflow / event       | Result                                                                                                           |
| ------------------ | ---------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Detect PHP Version | PHP QA Pipeline / push | success                                                                                                          |
| PHP QA (8.5)       | PHP QA Pipeline / push | success (finished 03:42:46Z; it was still running when verification started and I waited for it)                 |
| QA Pipeline        | CI / pull_request      | success. Its log shows `PHPStan Turbo: enabled (version 6351afb)` and `ALL TESTS PASSING`                        |
| Coverage Report    | PHP QA Pipeline / push | skipped. This is expected: the job has `if: github.event_name == 'pull_request'` and this run's event was `push` |

## 2. Scope

The diff matches the description:

- 4 new src classes;
- a 12-line change to `PhpstanTool`: a constructor with a default probe, plus one `writeln` in text mode;
- docs and a CHANGELOG sentence;
- tests;
- the plan and journal.

There is nothing unrelated, no debug output, no secrets, no lock-file or `qaConfig/` change, and no new `ignoreErrors`, baseline or inline ignore.

**The lane outcome is unchanged (reproduced).** With `vendor-phar/turbo-ext` moved aside:

- `QA_READONLY=1 CI=true bin/qa -t stan` printed `NOT RUNNING`, PHPStan's four `Turbo …` lines and the fix;
- it then ran the analysis, reported `[OK] No errors` and exited 0.

So D2 is not pre-empted.

## 3. Project rules

**Defence Before Fix ordering (reproduced).** The red commit `3763778` adds tests only. Run at that commit:

- `TurboStatusTest` gave 5 errors: `Class "LTS\PHPQA\Turbo\TurboStatus" not found`.
- `DiagnoseTurboProbeTest` gave 4 errors: `DiagnoseTurboProbe not found`.
- `PhpstanToolTest` gave 38 errors: `Interface TurboProbeInterface not found`.

The fix `01564fd` follows. This is the expected red for a new feature: the missing symbols are the behaviour under test.

**Changelog (reproduced).**

- `GITHUB_BASE_REF=php8.5 QA_READONLY=1 CI=true bin/qa -t cl` passed: "5 watched files changed; recorded by 1 new `## Unreleased` entry".
- The sentence extends the existing Unreleased "Added" Turbo entry.
- The test-only and plan commits carry `Changelog: none —` trailers.
- Run on a plain detached HEAD the lane fails, but only because "HEAD is detached and this is not a pull request build", which is an environment artefact.

**Branch name:** `feature/…` is allowed.

## 4. Correctness

### Reproduced

- **Targeted PHPUnit with coverage and `failOnRisky`/`requireCoverageMetadata`.** Each of these passed (exit 0):

  - `tests/Small/Turbo`
  - `tests/Small/Pipeline/Lane/Phpstan`
  - `PhpstanToolTest` (38 tests, 167 assertions)
  - `InProcessLanesTest`
  - `PipelineBuilderTest`
  - `ShippedToolLocatorTest`
  - `ActiveRulesListerTest`
  - `ActiveRulesListerSelfCheckTest`

- **PHPStan lane over the whole repository:** `QA_READONLY=1 CI=true bin/qa -t stan` exit 0, printing `PHPStan Turbo: enabled (version 6351afb)`.

- **Large test `ConsumerPhpstanLaneRunsWithTurboTest`:**

  - it passes;
  - with `vendor-phar/turbo-ext` moved aside it **fails** with the lane's `NOT RUNNING` output, so the test can fail.

- **Real `diagnose` behaviour matches the parser's assumptions:**

  - With a valid config, exit 0. The `Turbo extension: enabled (version 6351afb)` lines go to stdout, undecorated, when not on a TTY.
  - With an invalid config, exit 1 and no Turbo lines, so the probe gives `Unknown`.

- **The upstream wording of `Turbo extension:`, read from `phpstan.phar` `src/Turbo/TurboDiagnoseExtension.php`:**

  - `enabled (version …)`
  - `enabled in worker processes (…)`
  - `not loaded`
  - `inactive (extension version X, expected Y)`

  All four map correctly: the two `enabled…` forms give Enabled; the rest give Missing or NotBuiltForHost, depending on the manifest.

### Tests can fail

- `textModeReportsWhetherTurboIsRunning` asserts the printed line and the wrapper path that was asked about.
- `jsonAndAgentModesDoNotAskAboutTurbo` asserts the probe was never called.
- `theShippedLaneAsksThePharThroughDiagnose` asserts that the default constructor's first process is `diagnose`.
- `DiagnoseTurboProbeTest` covers four things:
  - the exact command, cwd and `streamOutput:false`;
  - manifest-shipped versus Windows;
  - a failed diagnose giving Unknown;
  - the `TurboStatus` lines exactly.

### Documented routes

- **`new PhpstanTool()`, as used by `ShippedTools` and any `PipelineBuilder`:** the default argument `new DiagnoseTurboProbe()` keeps it working. The `ShippedToolLocator` and `PipelineBuilder` tests pass.
- **A `qaConfig/tools/phpstan.php` override returning its own `ToolInterface`:** unaffected, because it does not go through `PhpstanTool`.
- `PhpstanTool` is `final`, so no subclass can be broken.

### Inputs this repository never has (read; reasoning given)

- **No manifest, an unreadable or malformed manifest, or a manifest for another PHPStan version.**
  - `DiagnoseTurboProbe::shippedForHost` (DiagnoseTurboProbe.php:44) would throw. That exception would escape the lane, and `ToolExecutor` does not catch it, so the run would abort in `QaApplication`.
  - However, `PharToolsVerifier::verifyTurboManifest` already refuses to start the run in exactly these cases: a missing file, a parse failure, or a version that differs from the phar. That check runs in preflight, before any lane. It is already on `php8.5`, not added here.
  - So in a real run the probe never sees a bad manifest. **This is not a regression** (see note N1).
- **musl, arm64, macOS Apple silicon, Intel Mac and Windows hosts.**
  - `TurboPlatform::assetName` returns null for unsupported hosts. Those give `NotBuiltForHost`, and the run is unchanged.
  - musl, arm64 and macOS arm64 have assets in the manifest. There a missing binary is reported as `NOT RUNNING`, and the run is still unchanged.
  - `TurboPlatform::fromRuntime` is the code `turbo-install` already runs at every `composer install`.
- **A change in diagnose's output format.** With no `Turbo extension:` line the probe gives `Unknown`: one line, and no failure.
- **Diagnose exiting non-zero.** The probe gives `Unknown`, and the following `analyse` reports the config error as before.
- **Diagnose hanging or slow.** No timeout is set, and the `analyse` call has none either. A hang in `diagnose` would hang the lane exactly as a hang in `analyse` already would. The cost is one extra PHPStan start per text-mode run (about 0.8 s, as stated). See note N2.

## Notes (non-blocking)

- **N1 — `src/Pipeline/Lane/Phpstan/DiagnoseTurboProbe.php:39,44-45`.** The probe can throw. Both `TurboManifest::fromJson` / `\Safe\file_get_contents` and `\Safe\glob` in `TurboPlatform::fromRuntime` can raise. If they do, the exception escapes `PhpstanTool::run` and aborts the whole run, even though the line is informational.
  - Today the preflight guarantees the manifest, so this is not reachable in a normal run.
  - Two cheap hardening options:
    - catch and report `unknown` (keeping the exception message, per `phpqaci.silentCatch`);
    - or compute `shippedForHost` only when diagnose did not report `enabled`. It is currently evaluated eagerly even on the Enabled path.
- **N2 — `src/Pipeline/Lane/Phpstan/DiagnoseTurboProbe.php:32`.** The `diagnose` call has no timeout. This is consistent with `analyse`, so it adds no new failure mode, but a bounded timeout (say 60 s), mapped to `Unknown`, would keep a purely informational probe from ever blocking the lane.
- **N3 — `src/Turbo/TurboStatus.php:68,81`.** The `Missing` advice is always "Run turbo-install". When a *different* `phpstan_turbo` version is loaded through `php.ini`, diagnose prints `inactive (extension version X, expected Y)`, and `turbo-install` will not fix that. PHPStan's own lines are printed underneath, so the reader can tell, but the advice could mention a `php.ini`-loaded extension.
- **N4 — `tests/Large/PHPStan/ConsumerPhpstanLaneRunsWithTurboTest.php:46`.** The test requires `enabled` unconditionally. A contributor on a host with no shipped build (an Intel Mac, Windows) will see it fail rather than skip. CI (x86_64 glibc) is fine. A skip when `TurboPlatform::fromRuntime()->assetName(...)` is null would make the test honest everywhere.
- **N5 — `src/Pipeline/Lane/Phpstan/DiagnoseTurboProbe.php:39`.** The `$result->succeeded() ? $result->output : ''` ternary is effectively untested. The failing-diagnose fixture contains no `Turbo` lines, so passing its output through would also give Unknown. This is harmless, and it is noted only for test strength.

## Blocking findings

None.
