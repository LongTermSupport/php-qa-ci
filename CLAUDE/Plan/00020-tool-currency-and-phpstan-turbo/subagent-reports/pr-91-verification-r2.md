# PR #91 verification, round 2: the PHPStan lane says whether Turbo is running

- **Verdict: PASS WITH NOTES**
- **Head verified:** `8eb8c892e31d1f2250683213ff56f01e36bef417` (`feature/plan-00020-turbo-status`)
- **Base read:** `php8.5` at `43a4b1a552745c31296c1b02721c64bbd317156c`. This is the merge base, and `origin/php8.5` is an ancestor of the head.
- **Procedure:** CLAUDE/pr-verification.md. The PR branch's copy is identical to the main checkout's.
- **How it was checked:**
  - Read through `gh` and `git diff origin/php8.5...origin/feature/plan-00020-turbo-status`.
  - Reproduced in a throwaway detached worktree `untracked/worktrees/verify-91` (`composer install`, exit 0), which has been removed.
  - The author's worktree was not touched.
  - Nothing was posted to the PR.
- **What changed since round 1 (head `ace4dc9`):**
  - `8ac467e` changes tests only. It addresses round-1 notes N4 and N5.
  - `8eb8c89` is a plan record: the round-1 report and a journal handoff.
  - Round-1 notes N1 to N3 are tracked as issue #92.

Each finding below is marked **[R]** (reproduced) or **[read]** (read only).

## 1. CI (head 8eb8c89): all concluded

Both workflow runs are on `headSha 8eb8c892…`. Both were still pending when verification started; I waited until they concluded.

| Check              | Workflow / event                         | Result                                                                                                                                                        |
| ------------------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Detect PHP Version | PHP QA Pipeline / push                   | pass                                                                                                                                                          |
| PHP QA (8.5)       | PHP QA Pipeline / push (run 37884922910) | pass (21m37s). The log shows `PHPStan Turbo: enabled (version 6351afb)`, `OK (1935 tests, 5404 assertions)`, `Covered Code MSI: 100%` and `ALL TESTS PASSING` |
| QA Pipeline        | CI / pull_request (run 37884925764)      | pass (1m52s). The log shows `PHPStan Turbo: enabled (version 6351afb)`, `OK (1935 tests …)` and `ALL TESTS PASSING`                                           |
| Coverage Report    | PHP QA Pipeline / push                   | skipped. This is expected: `.github/workflows/qa.yml:317` is `if: github.event_name == 'pull_request'`, and this run's event was `push`                       |

## 2. Scope [read]

Across the whole PR against `php8.5` there are 20 files: +792 and −43.

- **Source:** four new classes, `DiagnoseTurboProbe`, `TurboProbeInterface`, `TurboStatus` and `TurboStateEnum`. `PhpstanTool` gains a constructor with a default probe and one `writeln` in text mode.
- **Docs and changelog:** `docs/tools/phpstan.md`, plus one CHANGELOG sentence.
- **Tests:** tests for the new code, and `UsesClass` declarations added in five existing tests.
- **Plan records:** the plan, the journal and the round-1 report.

The diff matches the PR description, including the two new commits it lists. There is:

- no change to a lock file, `qaConfig/`, a baseline or `ignoreErrors`;
- no `@phpstan-ignore`;
- no debug output;
- no secret.

## 3. Project rules

**Defence Before Fix ordering.**

- [read] The red commit `3763778` comes before the fix `01564fd`. Round 1 reproduced the red, and nothing since changes it.
- [read] `8ac467e` adds only tests that harden existing behaviour, so it needs no red/fix split.

**Changelog.**

- [R] `GITHUB_BASE_REF=php8.5 QA_READONLY=1 CI=true bin/qa -t cl` exits 0 and reports "5 watched files changed; recorded by 1 new `## Unreleased` entry".
- [read] Every commit that does not touch a consumer-visible path carries a trailer:
  - `ace4dc9`: `Changelog: none — test attributes only`
  - `8ac467e`: `Changelog: none — tests only`
  - `8eb8c89`: `Changelog: none — plan record only`

**Branch name** \[read\]: `feature/…` is an allowed prefix.

**Suppressions** \[read\]: none added.

## 4. Correctness

### Round-2 delta

**`aDiagnoseThatFailsLeavesTheStateUnknown`** (`tests/Small/Pipeline/Lane/Phpstan/DiagnoseTurboProbeTest.php:107-115`) **can now fail. [R]**

- The fixture now puts `Turbo extension: enabled (version 6351afb)` before the config error, on a host the manifest ships a build for.
- Mutant: in `src/Pipeline/Lane/Phpstan/DiagnoseTurboProbe.php:39` I replaced `$result->succeeded() ? $result->output : ''` with `$result->output`.
  - The test **failed**: "Failed asserting that two variables reference the same object", because it got Enabled where it expected Unknown. That is 1 failure out of 4 tests.
  - I then restored the file from `HEAD`, and `git status` was clean.
- Round-1 note N5 is closed.
- Removing the old fixture (diagnose failed with no Turbo lines) loses no coverage: `TurboStatusTest::states` still yields "diagnose said nothing about Turbo" as Unknown (`tests/Small/Turbo/TurboStatusTest.php:51`).

**The skip in `ConsumerPhpstanLaneRunsWithTurboTest`** (`tests/Large/PHPStan/ConsumerPhpstanLaneRunsWithTurboTest.php:31-40`) **is correct. [R]**

- It skips only when `TurboPlatform::fromRuntime()->assetName()` is null, or when the manifest has no digest for that asset. That is the same test `DiagnoseTurboProbe::shippedForHost` applies, so the test and the lane agree on which hosts should run Turbo.
- This host is PHP 8.5.11 on x86_64 with glibc 2.36. On it, `assetName` gives `php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip` with digest `62c7222f…`, so the test does **not** skip.
- Run with `XDEBUG_MODE=coverage --fail-on-risky --display-skipped`, the result was `OK (1 test, 2 assertions)` with no skip.
- With `vendor-phar/turbo-ext` renamed aside, the test still runs and **fails**. The lane prints `PHPStan Turbo: NOT RUNNING … Turbo extension: not loaded`. The skip depends on the manifest, not on the binary, so a missing binary on a shipped host stays a failure rather than turning into a skip. I restored the binary afterwards.
- Round-1 note N4 is closed.
- [read] The nullable `$consumer` with `?->remove()` in `tearDown`, and `assertNotNull` in the test, handle the skipped path correctly. A skip raised in `setUp` still runs `tearDown`.

### Whole PR, re-confirmed

**Targeted PHPUnit [R].** The run used `XDEBUG_MODE=coverage` and `--fail-on-risky`, with coverage generated, and returned `OK (153 tests, 624 assertions)`, exit 0. It covered:

- `tests/Small/Pipeline/Lane/Phpstan`
- `tests/Small/Turbo`
- `PhpstanToolTest`
- `InProcessLanesTest`
- `PipelineBuilderTest`
- `ShippedToolLocatorTest`
- `ActiveRulesListerTest`

**Lanes on the head [R].** Every lane below was run with `QA_READONLY=1 CI=true`, and each exited 0:

- `-t stan`, which printed `PHPStan Turbo: enabled (version 6351afb)` and `[OK] No errors`;
- `-t fixer`;
- `-t rector`;
- `-t cl`.

**Documented routes [read].** Nothing changed since round 1:

- `new PhpstanTool()` keeps working through the default `new DiagnoseTurboProbe()`.
- A `qaConfig/tools/phpstan.php` override does not go through `PhpstanTool`.
- `--json` and agent mode do not probe.

**Inputs this repository never has [read].** Round 1's analysis still holds:

- A bad or missing manifest is refused in preflight by `PharToolsVerifier`, before the lane runs.
- Unsupported hosts get `NotBuiltForHost`.
- A failed diagnose gives `Unknown`.

In every one of these cases the lane's outcome is unchanged.

## 5. Mergeability [read]

- `mergeable=MERGEABLE` and `mergeStateStatus=CLEAN`. `reviewDecision` is empty, so no approval is owed.
- `origin/php8.5` (`43a4b1a`) is an ancestor of the head, so the branch needs no update.
- The PR is not stacked. Its 7 commits are all its own.
- The PR has no comments.

## Notes (non-blocking)

- **N1** (`CLAUDE/Plan/00020-tool-currency-and-phpstan-turbo/JOURNAL/00020-Journal-26-10-09.md`, the `03:54 · handoff` entry) \[read\]:
  - The entry says `8ac467e` "is NOT pushed" and gives resume steps.
  - That was true when it was written, but it is stale now that the commit is pushed and verified.
  - A journal is a dated record, so this is acceptable. The next journal entry could note that the handoff was resumed.
- **N2** (carried over): round-1 notes N1 to N3 remain open as issue #92. The two ways the probe can throw, the missing timeout on `diagnose`, and the `turbo-install` advice for a php.ini-loaded extension are all unchanged by this round. None of them blocks.
- **N3** (`tests/Large/PHPStan/ConsumerPhpstanLaneRunsWithTurboTest.php:34`) \[read\]:
  - The skip decision uses the PHP that runs PHPUnit, while the consumer's `bin/qa` uses `PHP_QA_CI_PHP_EXECUTABLE` (default `php`).
  - These are the same binary in every normal setup. They differ only when a contributor points the executable at another PHP, and then the test could fail or skip wrongly.
  - This is negligible.

## Blocking findings

None.
