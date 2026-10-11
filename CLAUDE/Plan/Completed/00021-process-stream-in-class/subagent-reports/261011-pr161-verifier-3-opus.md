# PR #161 verification, third pass: process stream in class

- **Head verified**: `e39c42095f56aa8344ea5e22719d232ad29e3f4b` (`bugfix/process-stream-in-class` into `php8.5`)
- **Previous verdicts**: PASS WITH NOTES on `b544060` and on `669a24d`
- **Verdict**: **PASS WITH NOTES**. Nothing blocking. CI is still running, so the merge waits for it.

## 1. CI on `e39c420`

| Check              | State                 |
| ------------------ | --------------------- |
| Detect PHP Version | pass                  |
| PHP QA (8.5)       | pending (in progress) |
| QA Pipeline        | pending (in progress) |

Running is not a pass: the merge waits for both pending checks to conclude success.
`mergeStateStatus` BLOCKED (pending required checks), `mergeable` MERGEABLE, `reviewDecision` empty, no PR comments.

## 2. Scope of the delta (`669a24d..e39c420`)

- `6168f9a`: fixture only (`tests/assets/PHPStan/ProcessStream/NotReportable.php` adds a namespace function `readline_first()` and an unqualified call to it). Trailer `Changelog: none — test fixture only`.
- `86e4b2b`: `src/PHPStan/Rules/ForbidProcessStreamRule.php` gains a `ReflectionProvider` constructor parameter and `isBuiltIn()`; the rule test passes `$this->createReflectionProvider()`. No changelog line needed beyond the existing Added entry (the rule is new in this PR's Unreleased).
- `e39c420`: plan journal and the second verifier's report copied into the plan. Trailer `Changelog: none — plan record only`.
- Nothing unrelated, no debug output, no secrets, no lock file, no suppression or `ignoreErrors` entry.

## 3. Defence Before Fix ordering (reproduced)

Separate throwaway worktrees at `6168f9a` and at the head, `composer install`, `bin/phpunit --no-coverage tests/Small/PHPStan/Rules/ForbidProcessStreamRuleTest.php`:

- `6168f9a`: exit 1, 1 failure: the not-reported test gets `31: readline_first() inside a function reaches the process's own stream ...`. Genuinely red, for the right reason.
- `e39c420`: exit 0, OK (3 tests, 3 assertions). Green.

## 4. Real readline calls still reported through the shipped phar (reproduced)

Probe `untracked/scratch/verify161c/Probe.php` (namespace `Probe\Ns`, inside a class method): `readline()`, `\readline()`, `readline_add_history()`, `\readline_add_history()`, `readline_info()`, a namespace-own `readline_first()`, a namespace-own `readlineCount()`, and `\READLINE()`. Run with `vendor-phar/phpstan.phar` from the head worktree.

- **ext-readline loaded** (host PHP 8.5.11, `20-readline.ini`): with the rule alone (`probe.neon`) and with the head's `rules-optional.neon` included (`probe-optional.neon`), reported lines 11, 12, 13, 14, 15, 18 (all real calls, including `\READLINE`); lines 16 (`readline_first`) and 17 (`readlineCount`) not reported.
- **ext-readline not loaded** (`php -n` plus phar, tokenizer, ctype, mbstring, posix, iconv, xml family, intl, curl, fileinfo; `function_exists('readline_add_history')` and `function_exists('readline')` both false; script `untracked/scratch/verify161c/noreadline.bash`): exactly the same six findings, lines 11-15 and 18. PHPStan answers `hasFunction()`/`isBuiltin()` for readline from its bundled stubs, not from the running PHP, so a CI host without ext-readline still reports. No silent loss of the defence.

## 5. Rule loads with its new constructor (reproduced)

- `rules-optional.neon:161` registers the class under `rules:` with no arguments; the container autowires `ReflectionProvider`. Confirmed by the `probe-optional.neon` run above loading and reporting.
- `CI=true bin/qa -t stan` on the head worktree: exit 0, `[OK] No errors` (php-qa-ci's own `qaConfig/phpstan.neon` includes `../rules-optional.neon`).
- The only constructor call site is the rule test (`git grep "new ForbidProcessStreamRule"`).

## 6. Earlier notes

- N1, N3, N4-N6: unchanged by the delta, dispositions from the earlier reports stand.
- N2a (namespace-own `readline_*` reported): fixed for a namespace function whose name is not a PHP built-in (reproduced above). See note N7 for the case it does not cover.

## 7. Mergeability

MERGEABLE; base `php8.5` is an ancestor; the branch carries only its own commits.

## Blocking findings

None.

## Notes

- **CI pending**: `PHP QA (8.5)` and `QA Pipeline` in progress on `e39c420`; the merge waits for both to succeed.
- **N7** (`src/PHPStan/Rules/ForbidProcessStreamRule.php:196-200`): `isBuiltIn()` checks the name as a global (`Name\FullyQualified($function)`) rather than resolving it against the call's namespace (e.g. `$reflectionProvider->resolveFunctionName($node->name, $scope)` or `getFunction($node->name, $scope)`). A namespace that defines its own function with the exact name of a real built-in (`readline()`, `readline_add_history()`) and calls it unqualified is still reported; reproduced with `untracked/scratch/verify161c/own/Probe.php` (lines 11 and 12 reported). False positive only, rare, safe direction; the docblock at line 195 ("a project's own function of a readline name reads nothing") slightly overstates what the check does. Cheap to fix in scope (pass the scope, resolve the name, add a fixture), otherwise raise as an issue.
- **N8** (`CHANGELOG.md:40`): the Added entry lists `readline()` only, while the rule and its doc page (`docs/phpstan-rules/forbid-process-stream.md:17`) cover every `readline_*()` function. Cosmetic.

## Artefacts

- `untracked/scratch/verify161c/` (probes, neon files, `noreadline.bash`, `out-probe.neon.txt`, `own/out.txt`, `stan.log`)

Throwaway worktrees `untracked/worktrees/verify-161c` and `verify-161c-red` have been removed.
