# Lanes ported: phpstan and phpArkitect

Subagent report for Plan 00003 (PHP pipeline rewrite). Nothing committed.

## Classes

| Lane        | Class                                        | Name          | Identifier             |
| ----------- | -------------------------------------------- | ------------- | ---------------------- |
| PHPStan     | `LTS\PHPQA\Pipeline\Lane\PhpstanTool`        | `phpstan`     | `phpqaci.phpstan`      |
| PHPArkitect | `LTS\PHPQA\Pipeline\Lane\PhpArkitectTool`    | `phpArkitect` | `phpqaci.phpArkitect`  |

Files:

- `src/Pipeline/Lane/PhpstanTool.php`
- `src/Pipeline/Lane/PhpArkitectTool.php`
- `tests/Small/Pipeline/Lane/PhpstanToolTest.php` (10 tests)
- `tests/Small/Pipeline/Lane/PhpArkitectToolTest.php` (8 tests)
- `docs/tools/phpArkitect.md` (new)
- `docs/tools/phpstan.md` (new "How the lane runs" section, placed before "Configuration")

Public constants the coordinator may want: `PhpstanTool::LOG_DIR` (`phpstan_logs`),
`LOG_FILE`, `JSON_FILE`, `WRAPPER_NEON`; `PhpArkitectTool::LOG_DIR` (`phparkitect_logs`),
`LOG_FILE`.

## Behaviour ported

PhpstanTool:

- Writes `<logDir>/phpstan-parallel.neon` including the resolved `phpstan.neon` with
  `parallel.maximumNumberOfProcesses = halfCpuThreads`; prints the "limiting to N" line.
- argv `analyse <pathsToCheck> -c <wrapper>` plus `--no-progress` when `ci`.
- Text mode: streamed; output written to `phpstan.log`; archived via LogArchiver. Exit 0
  passed; exit 1 failed with the tautology note when the output matches the four identifiers,
  then the identifier trailer; exit above 1 prints the "PHPStan Crashed!!" block, re-runs with
  `--debug -v` (no `--no-progress`, matching the Bash), returns crashed.
- JSON mode: `--no-progress --error-format=json`, not streamed; written to `phpstan.json`,
  raw to `$context->stdout`, archived; 0/1/above 1 mapped to passed/failed/crashed, no re-run.

PhpArkitectTool:

- `useArkitect` false: skipped with the "disabled" line. Entry config missing: skipped with the
  "no entry config resolved" line. Neither runs a process.
- Env passed on the ProcessSpecDto: SRC_DIR, RULES_DEFAULT, RULES_OPTIONAL,
  RULES_OPTIONAL_SYMFONY, CONSUMER_API_BOUNDARY (all via `configPath`, so `qaConfig/` overrides
  win), EXCLUDE_PATHS newline-joined or empty.
- argv `check --config=<entry> --autoload=<root>/vendor/autoload.php --no-interaction`;
  output streamed, written to `phparkitect.log`, archived. Exit 1 failed with trailer; above 1
  prints the crash block and returns crashed.

## Not ported verbatim, and why

- The tautology note's final line reads "or an inline PHPStan ignore comment" instead of the
  Bash fragment's literal annotation name. The daemon's QA-suppression guard blocks any source
  file containing that token, so it cannot be written into the PHP class. Meaning is unchanged.
- The Bash `PHPArkitect: disabled (useArkitect=$useArkitect)` interpolated the raw value; the
  DTO holds a bool, so the PHP prints `useArkitect=0`.
- Retry loops are not reproduced: the runner owns retries per the contract, and Crashed is
  never retried.

## Verification

```text
bin/phpunit -c qaConfig/phpunit.xml --no-coverage tests/Small/Pipeline/Lane/PhpstanToolTest.php tests/Small/Pipeline/Lane/PhpArkitectToolTest.php
OK (18 tests, 90 assertions)

CI=true QA_READONLY=1 bin/qa -t stan -p <file>   for each of the four PHP files
[OK] No errors   (x4)

php bin/mdlinks   exit 0
```

Logs: `untracked/scratch/lanes-phpstan-arkitect-phpunit.log`, `untracked/scratch/stan-*.log`.

## Test note for the coordinator

`PhpInvoker::withoutXdebug` asks the runner for the PHP version before every invocation and
looks for `var/qa/phpqa-no-xdebug.<version>.ini`. The tests pre-seed that ini and queue a
version result before each tool result. Any future lane test driving `withoutXdebug` through
the FakeProcessRunner needs the same; a shared helper on ContextFactory would remove the
duplication (not added, outside my file scope).
