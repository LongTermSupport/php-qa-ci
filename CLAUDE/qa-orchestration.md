# QA orchestration: the run → fix → run cycle

**This file is the single source of truth for how an agent drives php-qa-ci tools to green.**
The `qa` skill (and any other skill or agent that cycles a tool) points here and carries only
its invocation mechanics. Which command to run, and when, is NOT defined here: that is
[prepush-verification.md](prepush-verification.md), and nothing below restates it. In a
consuming project both files live under `vendor/lts/php-qa-ci/CLAUDE/`.

## The rule that governs everything else

Cycle until the tool reports clean or an escalation trigger fires. Never run once, fix once
and ask "what next?". Never pause between iterations for permission. Report when done, or when
stuck, and not in between.

Every execution goes through the project's `qa` entry point in its composer bin directory
(`composer config bin-dir`, default `vendor/bin`); never a bare phar or binary, which bypasses
the pipeline's configuration, caching and log rotation. A full-codebase run is single-threaded:
one executor at a time, after all editing has finished.

The main context never runs the tool itself. Runner skills launch cheap agents that execute
the tool and return a summary, so tool output stays out of the expensive context.

## Routing: which skill runs and fixes each tool

| Tool (`-t` token)                                               | Runner skill     | Runner agent                     | Fix path                                                                       |
| --------------------------------------------------------------- | ---------------- | -------------------------------- | ------------------------------------------------------------------------------ |
| `phpstan` / `stan`                                              | `phpstan-runner` | `php-qa-ci_phpstan-runner`       | `phpstan-fixer` skill (sonnet agent)                                           |
| `phpunit` / `unit`, `allTests`                                  | `phpunit-runner` | `php-qa-ci_phpunit-runner`       | `phpunit-fixer` skill (sonnet agent)                                           |
| `rector`, `fixer`, `allCS`                                      | `qa-tool-runner` | `php-qa-ci_qa-tool-runner`       | self-fixing: re-run until nothing changes                                      |
| `allStatic`                                                     | `qa-tool-runner` | `php-qa-ci_qa-tool-runner`       | PHPStan errors → `phpstan-fixer`; rest report-only                             |
| any other single lane (`lint`, `psr4`, `com`, `infection`, ...) | `qa-tool-runner` | `php-qa-ci_qa-tool-runner`       | report-only                                                                    |
| full pipeline (no `-t`)                                         | `qa-tool-runner` | `php-qa-ci_full-pipeline-runner` | per-lane: fix each failing lane by its own row, then re-run the whole pipeline |

Every lane a runner can be pointed at, with its aliases, is listed by `qa -h` in the project;
`vendor/bin/rules` lists the identifiers. A lane's own page under `docs/tools/` explains what
it checks and how to fix a failure.

## The loop

```
iteration = 0; previous = []
loop:
  1. run via the runner skill in the routing table (CI mode; -p <path> only when the user scoped the request)
  2. clean            → report success, stop
     crash            → escalate, stop
  3. iteration == 5, or the error set equals `previous` → escalate, stop
  4. dedicated fixer  → invoke it
     self-fixing tool → re-run (the run IS the fix)
     report-only tool → report, stop
  5. iteration++; previous = this run's errors; go to 1 with no pause
```

"Clean" is the lane's own verdict: PHPStan prints `No errors`; PHPUnit prints `OK (n tests, m assertions)` or a `Tests: … Failures: 0, Errors: 0` line; a self-fixing lane is clean when a
run changes no files; the full pipeline is clean when it exits 0 after `ALL TESTS PASSING`.

## Escalation triggers

Stop cycling and hand back to a human when any of these holds:

1. Five iterations have run.
2. The same errors persist across two consecutive iterations: the fixer cannot resolve them.
3. The tool crashes repeatedly (an exit code outside its documented pass/fail set).
4. The failing lane has no fixer and the finding needs a judgement (an Infection mutant, an
   architecture rule, a dependency to declare or drop).
5. The runner's runtime estimate exceeds five minutes and the user has not agreed to wait.

A fixer never suppresses: no baseline, no `@phpstan-ignore`, no loosened assertion, no deleted
test. When the only way to green is one of those, that is trigger 4. After a fix cycle reports
green in an orchestrated workflow, `php-qa-ci_qa-fix-auditor` reads the diff for exactly these.

## Reporting

One short summary after each runner or fixer step (tool, status, count of errors or fixes,
next action), and a final summary that names the tool, the iteration count, the fixes applied
and the log path. An escalation report names the trigger, the remaining error patterns with
counts, and what a human needs to decide. Say plainly when a lane was skipped or a run did not
finish; a green claim is the full pipeline's exit code, never a single lane's.

## Where the logs are

Every lane archives under `var/qa/<lane>_logs/`, timestamped, keeping the last ten runs:

- PHPStan: `var/qa/phpstan_logs/phpstan.log` (`phpstan.json` in `--json` mode)
- PHPUnit: `var/qa/phpunit_logs/phpunit.junit.xml` and `phpunit.log`
- Infection: `var/qa/infection/`
- Other lanes: the runner agent's captured stdout

## Preflight: documentation conflicts

Before the first run in a session, the `php-qa-ci_docs-conflict-checker` agent reads the
project's own instructions for anything that contradicts this procedure (a rule that forbids
agents, a read-only default, a bare-binary habit). A reported conflict stops the workflow with
the checker's suggested fix; the procedure resumes once the project's docs and this one agree.
