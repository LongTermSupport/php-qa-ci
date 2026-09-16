# Agent Mode

A second presentation of an ordinary QA run, for a caller that is a program rather than a
person: stdout carries a count and a path, and the findings themselves go into one JSON file
per analysed source file under `var/qa/`.

```bash
vendor/bin/qa --agent-mode -t phpstan -p src/Kernel.php
```

```text
PHPSTAN AGENT MODE: 14 errors in 1 file
REPORT: /project/var/qa/phpstan-file-reports/src/Kernel.php.json
ACTION REQUIRED: read that JSON report NOW and fix every error it lists, then re-run. Do not guess from this summary — it contains no findings, only counts.
```

## Why it exists

An agent editing PHP is linted after every write by a hook that runs the pipeline and injects
the whole of stdout into the agent's context. The ordinary output is a human console
transcript, so the agent pays for the preamble, the mode announcements, the banners, the lock
lines and the log-archive warning on every single edit. Measured on a consuming project, a
clean file costs 54 lines to say one thing, and a file with 108 findings costs 419.

Agent mode routes all of that decoration to a null output and puts the substance on disk. The
same two runs cost 2 lines and 3.

## The switch

`--agent-mode` on the command line, or `PHPQACI_AGENT_MODE=1` in the environment. They are
equivalent, and the environment form exists so a hook can select the mode without rewriting
the command it was configured to run.

Agent mode needs `-t <tool>`. There is no terse report for a whole pipeline, so a run without
a tool is refused.

## Which tools support it

A lane declares the capability through `supportsAgentMode` on its
[ToolDefinitionDto](../src/Pipeline/Tool/Dto/ToolDefinitionDto.php); the flag defaults to
false, so a tool supports agent mode only by saying so.

| Tool                        | Agent mode | What happens when it is selected                  |
| --------------------------- | ---------- | ------------------------------------------------- |
| `phpstan`                   | Supported  | Terse stdout, per-file JSON reports, an index     |
| Every other shipped lane    | Refused    | A usage error naming the supporting tools, exit 1 |
| Every phase runner (`all*`) | Refused    | A usage error, exit 1                             |
| No `-t` at all              | Refused    | A usage error, exit 1                             |

**A tool without support refuses the run; it never falls back to its ordinary output.** A
caller that asked for a report and silently received a console transcript has no way to
distinguish that from a tool with nothing to say, and would go looking for a report nothing
had written. `vendor/bin/qa --agent-mode` with no `-t` prints the supporting tools.

A consuming project that registers its own lane through `qaConfig/pipeline.php` opts in the
same way, by constructing its `ToolDefinitionDto` with `supportsAgentMode: true` and writing
the reports itself.

## Exit codes

Every outcome has its own code, so a caller can branch on the result without parsing stdout.
Lock contention in particular is never reported as findings.

| Exit | Meaning                                        |
| ---- | ---------------------------------------------- |
| 0    | Analysed, no errors                            |
| 1    | Analysed, errors found                         |
| 2    | Lock held by another run, nothing analysed     |
| 3    | The tool crashed, or its output was unreadable |

Exit 2 writes no report. Overwriting a file's last real result with a lock-held placeholder
would destroy the finding the caller was about to act on, so the previous report stands and
stdout says the analysis did not happen.

## The report

One file per analysed source file, at the source file's own project-relative path with
`.json` appended:

```text
src/Kernel.php  ->  var/qa/phpstan-file-reports/src/Kernel.php.json
```

The extension is kept, so `Thing.php` and `Thing.phtml` cannot collide, and the mapping reads
in both directions by eye.

```json
{
  "tool": "phpstan",
  "path": "src/Kernel.php",
  "status": "errors",
  "run_at": "2026-09-16T08:57:46+00:00",
  "exit_code": 1,
  "error_count": 1,
  "errors": [
    {
      "line": 89,
      "message": "Call to static method allClasses() on an unknown class Arkitect\\Rules\\Rule.",
      "identifier": "class.notFound",
      "tip": "Learn more at https://phpstan.org/user-guide/discovering-symbols"
    }
  ],
  "log_path": "/project/var/qa/phpstan_logs/phpstan.json"
}
```

| Field         | Type   | Notes                                                             |
| ------------- | ------ | ----------------------------------------------------------------- |
| `tool`        | string | The canonical lane name                                           |
| `path`        | string | Project-relative, so a report is portable between machines        |
| `status`      | string | `clean`, `errors` or `crashed`                                    |
| `run_at`      | string | ISO 8601, UTC                                                     |
| `exit_code`   | int    | The code the same outcome produces on the command line            |
| `error_count` | int    | Derived from `errors`, so the two cannot disagree                 |
| `errors`      | list   | `line`, `message`, `identifier`, `tip`; null for anything omitted |
| `log_path`    | string | The raw analyser output the report was derived from               |

`line`, `identifier` and `tip` are null rather than zero or empty when the analyser did not
supply them, so a consumer can tell "no line given" from "line 0".

### The index

A whole-project run writes `var/qa/phpstan-file-reports/_index.json` naming every report it
wrote, which is what lets stdout stay three lines when fifty files have findings.

```json
{
  "tool": "phpstan",
  "status": "errors",
  "run_at": "2026-09-16T08:57:46+00:00",
  "exit_code": 1,
  "error_count": 3,
  "file_count": 2,
  "global_errors": [],
  "files": [
    { "path": "src/Kernel.php", "error_count": 2, "report": "/project/var/qa/phpstan-file-reports/src/Kernel.php.json" }
  ]
}
```

`global_errors` holds messages that belong to the run rather than to any file — a worker
dying, an ignore pattern that matched nothing. They are kept out of the per-file reports
because writing one into a file's report would attribute it to a file that did not cause it.

### A fixed error never stays red

PHPStan's JSON names only the files that have errors and says nothing about the rest, so
absence alone cannot distinguish "clean" from "not analysed". Agent mode clears the report
tree for the scope it is about to analyse before it writes anything: the specified path's
subtree for a `-p` run, the whole tree for a full run. Every report present afterwards was
written by the run that just finished.

A `-p` run naming a single file always writes that file's report, with `error_count: 0` when
it is clean. That write is what visibly erases the previous red one.

## Reading a report

```bash
# every message, one per line
jq -r '.errors[] | "\(.line): \(.message)"' var/qa/phpstan-file-reports/src/Kernel.php.json

# the identifiers to look up, deduplicated
jq -r '.errors[].identifier' var/qa/phpstan-file-reports/src/Kernel.php.json | sort -u

# after a whole-project run, the files worth opening
jq -r '.files[] | select(.error_count > 0) | .report' var/qa/phpstan-file-reports/_index.json
```

## Wiring it into a linting hook

A hook that already runs the pipeline per file needs no new command, only the variable:

```yaml
lint_on_edit:
  options:
    command_overrides:
      PHP:
        extended: "env XDEBUG_MODE=off PHPQACI_AGENT_MODE=1 bin/qa -t phpstan -p {file}"
```

The hook keeps its existing pass/fail behaviour on exit 0 and 1. Exit 2 means the analysis did
not run because another QA run held the lock, which is worth surfacing as "retry" rather than
as a lint failure.

## Agent mode and `--json`

Both read PHPStan's structured output; they differ in where it goes and who is meant to read
it.

|                 | `--json`                                     | `--agent-mode`                                     |
| --------------- | -------------------------------------------- | -------------------------------------------------- |
| Stdout          | The whole analyser report                    | A count, a path, an instruction                    |
| Decoration      | Diverted to stderr                           | Discarded                                          |
| On disk         | The raw report under `var/qa/phpstan_logs/`  | One report per source file, plus an index          |
| Intended caller | A pipe or a script that wants the raw report | A hook that injects stdout into an agent's context |

Use `--json` to pipe the analyser's own report somewhere. Use `--agent-mode` when the cost of
stdout is what matters.
