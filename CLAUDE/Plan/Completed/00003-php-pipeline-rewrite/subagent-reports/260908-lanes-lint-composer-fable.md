# Lanes ported: phpLint, composerChecks, composerRequireChecker, phploc

Subagent report for Plan 00003 (PHP pipeline rewrite). Nothing committed; no `includes/**`,
`ShippedTools.php` or `docs/phpstan-rules/README.md` touched.

## Classes

| Lane                     | Class                                                     | `name()`                 | Identifier                        |
| ------------------------ | --------------------------------------------------------- | ------------------------ | --------------------------------- |
| phpLint.inc.bash         | `src/Pipeline/Lane/PhpLintTool.php`                       | `phpLint`                | `phpqaci.phpLint`                 |
| composerChecks.inc.bash  | `src/Pipeline/Lane/ComposerChecksTool.php`                | `composerChecks`         | `phpqaci.composerChecks`          |
| composerRequireChecker   | `src/Pipeline/Lane/ComposerRequireCheckerTool.php`        | `composerRequireChecker` | `phpqaci.composerRequireChecker`  |
| phploc.inc.bash          | `src/Pipeline/Lane/PhplocTool.php`                        | `phploc`                 | `phpqaci.phploc`                  |

All `final readonly`, `IDENTIFIER` built from `RuleIdentifierInterface::PREFIX`, output only
via `ToolContext`, identifier trailer on every failure path.

## Tests (tests/Small/Pipeline/Lane/)

| Test class                       | Tests | Covers                                                                                                   |
| -------------------------------- | ----- | -------------------------------------------------------------------------------------------------------- |
| `PhpLintToolTest`                | 4     | exact argv over pathsToCheck, `--exclude <root>/<ignore>` per ignore, failure + identifier                |
| `ComposerChecksToolTest`         | 10    | read-only (`normalize --dry-run`) vs writable (`normalize`), informational diagnose, allow-plugins fail, would-modify guidance with `-t com`, failing normalize / dump-autoload, composer located on PATH |
| `ComposerRequireCheckerToolTest` | 4     | exact argv incl. `--config-file=<shipped default>`, project `qaConfig/` override wins, HOW TO FIX guidance + identifier |
| `PhplocToolTest`                 | 4     | skipped when `vendor/bin/phploc` absent (no process spawned), argv over pathsToCheck, non-zero exit still passes |

Total 22 tests, 68 assertions, all green:

```
composer dump-autoload -q && bin/phpunit -c qaConfig/phpunit.xml --no-coverage \
  tests/Small/Pipeline/Lane/PhpLintToolTest.php tests/Small/Pipeline/Lane/ComposerChecksToolTest.php \
  tests/Small/Pipeline/Lane/ComposerRequireCheckerToolTest.php tests/Small/Pipeline/Lane/PhplocToolTest.php
```

PHPStan (`CI=true QA_READONLY=1 bin/qa -t stan -p <file>`) reports `[OK] No errors` for each of
the four source and four test files (logs under `untracked/scratch/stan-<Name>.log`).
`bin/mdlinks` passes with the four new doc pages.

## Docs

`docs/tools/phpLint.md`, `docs/tools/composerChecks.md`, `docs/tools/composerRequireChecker.md`,
`docs/tools/phploc.md`, in the shape of `docs/tools/phpStrictTypes.md`. Aliases quoted from
`toolRegistry.inc.bash` (`lint`, `com`, `cr`, `loc`).

## Test-support note for the coordinator

`PhpInvoker::withoutXdebug()` issues a PHP version probe (and, on first use, an ini probe)
through the same `ProcessRunnerInterface` before the tool invocation. The tests pre-write
`var/qa/phpqa-no-xdebug.8.5.10.ini` in the temp project and queue `willSucceed('8.5.10')` before
every tool result. A shared helper on `FakeProcessRunner`/`ContextFactory` (e.g.
`willRunTool(result)`) would remove this repetition across all process-running lanes; not
added here because `tests/Support/` is outside my file list.

## Faithfulness notes / deviations

- **composerChecks, `composer` location**: the Bash uses `$(which composer)`; the port uses
  `Symfony\Component\Process\ExecutableFinder::find('composer', 'composer')` (falls back to the
  bare name). The constructor accepts an explicit path so tests assert exact argv.
- **composerChecks, allow-plugins probe** is run with `streamOutput: false`, matching the Bash
  command substitution (the value is compared, not displayed). The other three composer calls
  stream.
- **composerChecks, writable `normalize` / `dump-autoload` failures**: under the Bash `errexit`
  a non-zero exit aborts the fragment; the port returns `failed` with the identifier trailer.
- **composerChecks read-only guidance**: `reportReadOnlyWouldModify` text reproduced verbatim as
  a private method (the Bash `exit 1` becomes a `failed` result). If another lane agent ports
  the same helper, the coordinator may want to hoist it onto `ToolContext`.
- **composerRequireChecker**: the Bash retry loop is the runner's concern (`Failed` is
  retryable); the tool runs once. The Bash captures then echoes the output; the port streams it.
- **phploc**: the Bash has no exit-code handling (a non-zero exit would abort under errexit,
  contradicting the documented "cannot fail"); the port follows the documentation and the brief
  and always returns `passed`. It is `skipped('phploc not installed')` when the binary is
  absent, where the Bash silently did nothing.
- **phpLint**: the Bash `qaSimpleTool` retry loop is likewise left to the runner.
