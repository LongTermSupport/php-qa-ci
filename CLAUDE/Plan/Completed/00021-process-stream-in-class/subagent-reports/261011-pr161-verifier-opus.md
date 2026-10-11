# PR #161 verification: process stream in class

- **Pull request**: #161, `bugfix/process-stream-in-class` into `php8.5`
- **Head verified**: `b544060a5293847ddf1b8bf5d35202dc62287984`
- **Base read**: `origin/php8.5` at `11e3da5` (an ancestor of the head, so the branch is not behind)
- **Verdict**: **PASS WITH NOTES**. CI was still running when this was written, so the merge waits for it.

Labels: **[R]** means reproduced. **[read]** means found by reading the code or the history.

## 1. CI

When this was written, the head commit's checks were:

| Check                            | Status      |
| -------------------------------- | ----------- |
| `Detect PHP Version`             | SUCCESS     |
| `QA Pipeline` (required context) | IN_PROGRESS |
| `PHP QA (8.5)`                   | IN_PROGRESS |

`mergeStateStatus` was BLOCKED because the required check `QA Pipeline` was pending. `refUpdateRule` requires 0 approvals, and its only required status context is `QA Pipeline`.

**Not a pass yet. The merge waits for both running checks to conclude SUCCESS.**

## 2. Scope

The diff covers 41 files. Every one is the rule, its documentation and index entries, the 21 fixes with their `bin/` and `scripts/` wiring, the tests, the changelog, the CLAUDE.md active-defences line, or the plan and journal.

- Nothing unrelated is in the diff.
- No lock file is touched.
- No debug output and no secrets are added. A grep of the added lines for suppressions, baselines and debug calls found nothing.

## 3. Project rules

### Defence Before Fix ordering

The rule commits come first, with the instances still present, and the fix commit comes after them.

- **[R] `ce28dd6`**: `QA_READONLY=1 CI=true bin/qa -t stan` in a detached checkout exits 1 with "Found 16 errors", all `phpqaci.processStream`:

  - `ArkitectRuleProbe` 3
  - `ActiveRulesLister` 2
  - `SingleRuleReport` 2
  - `ShellCheckInstaller` 8
  - `GitHubTurboReleaseSource` 1

  This matches the plan's Task 1.3 breakdown and its technique-2 list. The two runner extensions are not reported, as claimed.

- **[R] `ac445cf`**: the same lane exits 1 with **"Found 21 errors"**, not 19. That is the 16 above, all still present, plus one each in `FullQaBlockerConfigurator`, `IssueMonitor`, `PhpstanDocsInstaller`, `PhpstanReplaceSync` and `TurboInstaller` (see note N1).

- **[R] Head**: the same lane exits 0, "[OK] No errors".

### Suppressions

No new suppression, baseline or `ignoreErrors` entry. `qaConfig/` is untouched.

### Changelog

- `ce28dd6`, `ac445cf` and `d439472` carry `## Unreleased` entries.
- `ab9339d` and `b6cb426` carry `Changelog: none — …` trailers, which are reasonable.
- `83ff6bd` changes `src/` with neither an entry nor a trailer. `b544060` then extends the Changed — breaking entry to cover it. The lane judges the branch as a whole, so this is acceptable.

The entries sit under **Changed — breaking**, for the `@api` signature changes, and **Added**, for the optional rule. Those headings are correct:

- `ShellCheckInstaller`, `TurboInstaller` and `FullQaBlockerConfigurator` are `@api`.
- Every other class whose signature changed is `@internal`: `ArkitectRuleProbe`, `SingleRuleReport`, `ActiveRulesLister`, `PhpstanDocsInstaller`, `PhpstanReplaceSync`, `IssueMonitor` and `GitHubTurboReleaseSource`.

### Branch name

`bugfix/` is an allowed prefix.

## 4. Correctness

### Entry points behave as before [R]

Each changed entry point was run in a checkout of `ac445cf` (the pre-fix code) and of the head, with stdin from `/dev/null`. Each run recorded the exit code, the stdout bytes and the stderr bytes. The results are identical:

| Entry point                                                                                           | Exit code | Streams                                 |
| ----------------------------------------------------------------------------------------------------- | --------- | --------------------------------------- |
| `bin/single-rule-report phpqaci.x`                                                                    | 2         | refusal on stderr, 58 bytes             |
| `bin/arkitect-rule`                                                                                   | 2         | usage on stderr, 399 bytes              |
| `bin/arkitect-rule a b c`                                                                             | 2         | usage on stderr, 399 bytes              |
| `scripts/issue-monitor`                                                                               | 2         | usage on stderr                         |
| `bin/shellcheck-install`, `bin/turbo-install`, `bin/phpstan-docs-install`, `bin/phpstan-replace-sync` | 0         | silent                                  |
| `bin/rules --agent-summary`                                                                           | 0         | 9742 bytes on stdout, nothing on stderr |

`bin/hooks-daemon-full-qa-blocker` was not run, because a hooks-daemon guard matched the command name. By reading, its change only passes `new ConsoleOutput()` where `main()` used to build one.

The other changes, by reading \[read\]:

- `ShellCheckInstaller`'s stdout and stderr writes moved from raw `fwrite` to `writeln(…, OUTPUT_RAW)` on `ConsoleOutput` and its error output. The bytes are the same.
- `bin/rules` sends `ActiveRulesLister`'s warnings to `StreamOutput(STDERR)`, the same stream as before.
- The callers of every changed signature in the tree were updated. Nothing in `scripts/`, `.github/` or `composer.json` calls these methods.

### Rule: documented forms and narrowings

The head's `ForbidProcessStreamRule` was run alone, through `vendor-phar/phpstan.phar`, over a scratch probe file [R]. Reported, as documented:

- a script-level closure and arrow function (the documentation narrows out only statements outside any closure);
- a class-constant `php://stderr` reaching `fopen`;
- `\STDIN` inside a class;
- `error_log($m, $nonConstantInt)` and `error_log($m, 0)`;
- an anonymous class writing to `STDOUT`.

Not reported, as documented: `error_log($m, 3, …)` and `fopen('php://output')`.

The fixture test passes: 3 tests [R].

Findings from the probe:

- **N2 (false positive, reproduced):** `ForbidProcessStreamRule.php:131` matches any function whose lower-cased name starts with `readline`. A user function `Probe\readlineCount()`, called unqualified, was reported as `readlinecount() inside a function reaches the process's own stream`. That contradicts the documentation's "`readline()` and the other `readline_*()` functions" (`docs/phpstan-rules/forbid-process-stream.md:17`). It needs an exact `readline` match or a `readline_` prefix. No instance exists in this repository, and consumers only meet it by opting in.
- **Gaps outside the documented scope (not defects):**
  - `copy('php://stdin', …)` is not reported, because `copy` is not one of the opening functions. The documentation lists the functions it covers, so nothing is mis-documented.
  - A trait no class uses is never analysed by PHPStan, so a stream use inside it is not reported. This is a PHPStan limitation.

### Tests

Each changed or new test file passes on the head with `GITHUB_AUTH_TOKEN=ci-token` set, and writes **0 bytes to stderr** [R]. The files:

- `ShellCheckInstallerTest`
- `tests/Small/Turbo/*`
- `SingleRuleReportTest`
- `ActiveRulesListerTest`
- `ArkitectRuleProbeTest`
- `tests/Small/IssueMonitor`
- `AgentContextIsCurrentTest`

Further points, by reading \[read\]:

- **The token tests are safe when CI sets the token** (`ShellCheckInstallerTest.php:150-160`, `GitHubTurboReleaseSourceTest.php:78-88`). Each one:

  - saves the original value with `getenv`;
  - sets or unsets the variable with `putenv`;
  - restores it in a `finally`;
  - asserts the exact header list.

  A CI-set token is overridden for the call and put back. The run with a token set confirms it.

- **The assertions can fail.** They compare the exact stderr and stdout text, the exact request URL list, the file bytes, the mode and the pin, so they are not tautologies.

- **N3:** `ShellCheckInstallerTest.php:287` says "A URL with no answer fails the test", but `self::fail()` at line 299 throws inside `($this->fetch)()`. `ShellCheckInstaller::get()` catches it with `catch (Throwable)` (`src/ShellCheck/ShellCheckInstaller.php:209`) and turns it into a "Note: could not fetch" line and a null, so an unexpected request does not fail the test directly. The tests still catch it, because:

  - most tests assert the error output exactly, or assert it is `''`;
  - the token test asserts the exact `$this->headers` list, where an extra request would appear.

  The protection holds through those assertions, so the docblock is inaccurate rather than a test that cannot fail.

- **N4:** `stagingDirectories()` (`ShellCheckInstallerTest.php:345`) globs `sys_get_temp_dir() . '/phpqa-shellcheck*'`. `ShellCheckBinaryTest` creates `phpqa-shellcheck-mode` in the same temporary directory. If the two ever ran concurrently, the before and after comparison could flake. paratest is not installed, so this is theoretical today.

## 5. Mergeability

The base is an ancestor of the head and `mergeable` is MERGEABLE. The branch carries only its own 7 commits on top of `php8.5`.

## Plan record fact-check (`CLAUDE/Plan/00021-process-stream-in-class/PLAN.md`)

- **Technique 2's list** is correct: it equals the 16 that `ce28dd6` reports, and the runner extensions are excluded [R].
- **Technique 3's five `main()`s** are exactly the five new findings at `ac445cf` [R].
- **N1, wrong count:** PLAN.md:115 ("19 reported"), journal line 45 ("19 reported, the 14 left of the first set plus five"), the `ac445cf` and `d439472` commit messages ("14 left of the first 16", "all 19 instances") and the PR body all say 19. The lane at `ac445cf` reports **21** [R]. `ac445cf` changes no `src/` file outside the rule, so all 16 original instances remain. Every one of the 21 is fixed at the head, so this is an error in the record only, not in the code. The Defence Before Fix record should state the true count.
- **The commit hashes** `ce28dd6`, `ac445cf`, `d439472` and `ab9339d` exist on the branch, and their contents match their stated roles [read]. The claim that Infection's initial run completes at `ab9339d` was not reproduced. The suite writing nothing to stderr is consistent with it, for the files run.

## Minor notes

- **N5:** the `83ff6bd` commit message prints "MSI 76%%" and "82%%", a doubled percent sign. This is cosmetic.
- **N6:** journal 04:13 records writing the rule file through Bash after the hooks daemon's TDD guard misresolved the test path to the main checkout. The test did exist and had been run red, so the TDD intent was met. It is still a guard bypassed by a different write route; raising the misresolution upstream would be worthwhile.

## Blocking findings

None. Merge once `QA Pipeline` and `PHP QA (8.5)` conclude SUCCESS on `b544060`.

Cheap, in-scope fixes to consider before merging, each of which would need a new verdict:

- N1: correct 19 to 21 in PLAN.md, the journal and the PR body.
- N2: fix the readline prefix match.
- N3: correct the docblock.

## Reproduction artefacts

All in the main working tree's `untracked/scratch/`:

- `v161-red-stan.log` (`ce28dd6`)
- `v161-ac445cf-stan.log`
- `v161-head-stan.log`
- `v161probe/` (rule probe)
- `v161-entrypoints.bash`

The throwaway worktrees have been removed.
