# Lanes ported: rector, phpCsFixer (plus the shared ReadOnlyGuidance helper)

Subagent report for Plan 00003 (PHP pipeline rewrite). Nothing committed; no `includes/**`,
`ShippedTools.php` or `docs/phpstan-rules/README.md` touched. The Bash fragments stay in place
until Phase 5.

## Classes

| Lane                  | Class                                      | `name()`     | Identifier           |
| --------------------- | ------------------------------------------ | ------------ | -------------------- |
| rector.inc.bash       | `src/Pipeline/Lane/RectorTool.php`         | `rector`     | `phpqaci.rector`     |
| phpCsFixer.inc.bash   | `src/Pipeline/Lane/PhpCsFixerTool.php`     | `phpCsFixer` | `phpqaci.phpCsFixer` |
| (shared helper)       | `src/Pipeline/Lane/ReadOnlyGuidance.php`   | n/a          | n/a                  |

`ReadOnlyGuidance::wouldModify(ToolContext $context, string $toolName, string $qaTarget): void`
prints the `reportReadOnlyWouldModify` block from `includes/functions.inc.bash` line for line
(minus the `exit 1`, which is the caller's outcome to decide). Both lanes call it and then
return `failed`. It is a candidate for the composerChecks lane too, which currently carries its
own copy of the same text.

Both lanes are `final readonly`, `IDENTIFIER` built from `RuleIdentifierInterface::PREFIX`,
output only via `ToolContext`, identifier trailer on every non-passing path, no process loop
(the runner retries `Failed`; `Crashed` is never retried).

### RectorTool behaviour

- Phar `$paths->pharDir/rector.phar`; missing → `crashed` with the three-line Bash message.
- Passes in order: `Safe` (`configPath('rector-safe.php')`, pathsToCheck), `PHPUnit`
  (`configPath('rector-phpunit.php')`, `[$paths->testsDir]`, preceded by the
  "Running PHPUnit Rector on …" line), then `Project Specific` for each of
  `<root>/rector.php` and `<root>/qaConfig/rector.php` that exists (pathsToCheck), else
  `PHP 8.5` (`configPath('rector-php85.php')`, pathsToCheck). When a project rector ran the
  "Skipping standard PHP 8.5 Rector…" line is printed.
- argv per pass: `process --autoload-file <root>/vendor/autoload.php --config <cfg>
  --clear-cache [--dry-run] <paths…>`; cwd `<root>`; env
  `rectorIgnorePaths` = newline-joined `pathsToIgnore` (empty string when none).
- Read-only: 0 passed; 2 → guidance (`Rector ('<label>')`, target `rector`) then failed; other
  → "failed with exit code N (a genuine error, not a pending-change diff)" then crashed.
  Writable: any non-zero → failed. First failing pass ends the lane.
- The `RECTOR_WRITABLE_ADVISORY` heredoc is printed verbatim after all passes succeed in a
  writable run only.

### PhpCsFixerTool behaviour

- Phar `$paths->pharDir/php-cs-fixer.phar`; argv `--config=<configPath('php_cs.php')>
  --cache-file=<varDir>/cache/php_cs.cache --allow-risky=yes --show-progress=dots
  --path-mode=intersection -vvv fix [--dry-run] <pathsToCheck…>`; cwd `<root>`.
- Output is streamed and written to `<varDir>/php-cs-fixer-output.log` (varDir created if
  absent).
- "Files that were not fixed due to errors" in the output → the ERROR block (wording
  "checking" in read-only, "fixing" in writable, as in Bash) then `crashed`, in either mode and
  regardless of exit code.
- Read-only: 0 passed; 8 → guidance (`PHP CS Fixer`, target `fixer`) then failed; other →
  "genuine error" line then crashed. Writable: non-zero → failed.

## Not ported / deliberately different

- The Bash `rectorIgnorePaths` skipped the export when `pathsToIgnore` was the literal
  `placeholder-ignore-item`. The PHP config has no placeholder concept, so the env is always
  set; an empty list gives an empty string, which `rector-safe.php`'s `array_filter` treats as
  no skips. Equivalent behaviour.
- Retry loops are gone by contract (the runner retries `Failed`).
- `ToolResultDto::failed` in a writable Rector run carries `Rector ('<label>') failed (exit N)`
  as its summary; the Bash printed nothing beyond the retry prompt.

## Tests (tests/Small/Pipeline/Lane/)

| Test class              | Tests | Covers                                                                                                                                                      |
| ----------------------- | ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `RectorToolTest`        | 10    | exact argv of the three shipped passes and the two project-rector passes, `--dry-run` only in read-only, cwd + env per pass, empty env, exit 2/1 read-only, non-zero writable (fails, no advisory), advisory only in writable, missing phar crashes with no process, name/identifier |
| `PhpCsFixerToolTest`    | 9     | exact argv in both modes, log file written, exit 8/1 read-only, exit 8 writable fails, lint-error crash with exit 0 (read-only) and exit 8 (writable), name/identifier |
| `ReadOnlyGuidanceTest`  | 2     | tool name and `-t <target>` substituted, block delimiters                                                                                                    |

Total 21 tests, 104 assertions, all green:

```
composer dump-autoload -q && bin/phpunit -c qaConfig/phpunit.xml --no-coverage \
  tests/Small/Pipeline/Lane/RectorToolTest.php tests/Small/Pipeline/Lane/PhpCsFixerToolTest.php \
  tests/Small/Pipeline/Lane/ReadOnlyGuidanceTest.php
```

The tests follow the convention the other lane tests use for `PhpInvoker::withoutXdebug`:
pre-write `var/qa/phpqa-no-xdebug.8.5.10.ini` and queue a `8.5.10` version-probe answer before
each tool invocation. `RectorToolTest::rectorSpecs()` filters the probes out so argv assertions
index the Rector passes directly. The missing-phar test builds a `ProjectPathsDto` with an empty
`pharDir` via `QaConfigBuilder::defaults`.

PHPStan (`CI=true QA_READONLY=1 bin/qa -t stan -p <file>`) reports `[OK] No errors` for each of
the three source and three test files (logs under `untracked/scratch/lanes-rf-stan-<Name>.log`).
PHP CS Fixer read-only (`bin/qa -t fixer -p <file>`) reports 0 fixable files for all six.
`bin/mdlinks` has no findings for the two new docs.

## Docs

- `docs/tools/rector.md` (new, shape of phpStrictTypes.md)
- `docs/tools/phpCsFixer.md` (new, shape of phpStrictTypes.md)

## For the coordinator

- Wire `RectorTool` and `PhpCsFixerTool` into `ShippedTools` (phase codingStandards, in that
  order; aliases per `toolRegistry.inc.bash`: `r|rector`, `f|fixer|csfixer`).
- Add `phpqaci.rector` and `phpqaci.phpCsFixer` to the identifier index.
- Consider pointing the composerChecks lane at `ReadOnlyGuidance` to remove its duplicate text.
