# Plan 00012: agent mode terse stdout and per file reports

**Status**: Delivered — awaiting the Owner decision on the PR
**Created**: 2026-09-16
**Owner**: Joseph Edmonds
**Priority**: High

## Overview

An agent editing PHP in a php-qa-ci project is linted after every write: the hooks
daemon's `lint_on_edit` handler runs `bin/qa -t phpstan -p <file>` and injects the
whole of stdout into the agent's context. That stdout is a human console transcript,
so the agent pays for the decoration on every single edit. Measured on the
`accounts-api` consumer: a clean file costs 54 lines carrying one line of verdict,
and a red file costs 419 lines for 109 findings, of which roughly 45 are fixed
preamble, banners, the log-archive line and the high-log-count warning.

Agent mode is a second presentation of the same run. Stdout becomes a count, a report
path and an instruction; the substance moves to a stable per-file JSON report under
`var/qa/`, which the agent reads deliberately and only when there is something to fix.
The report is derived from PHPStan's own `--error-format=json`, never from the table
output, so no parser sits between the analyser and the fix.

The flag is a pipeline concept rather than a PHPStan one, because the daemon will
eventually lint more than PHPStan. Every tool therefore declares whether it supports
agent mode, and a tool that does not **refuses the run** rather than quietly emitting
its normal console output. A silent no-op would leave an agent believing it had a
report to read when it had nothing, which is worse than a plain failure.

## Goals

- A `--agent-mode` flag and a `PHPQACI_AGENT_MODE=1` environment equivalent, so a hook
  can select it without changing the command line it passes.
- Stdout in agent mode is at most three lines: a count, a report path, and a strongly
  worded instruction to read and fix the report.
- A per-file JSON report at `var/qa/phpstan-file-reports/<project-relative-path>.json`,
  stable per file, always holding the latest result for that file, jq-friendly.
- A clean analysis writes `error_count: 0` for the analysed file, so a stale red report
  cannot outlive the fix that made it wrong.
- A per-tool capability flag, with a fail-fast usage error for any tool that lacks it.
- Lock contention is distinguishable from findings by exit code and by the stdout line.

## Non-Goals

- Changing the hooks daemon. The environment variable exists precisely so the daemon
  needs no code change; a consumer adds it to `lint_on_edit`'s `command_overrides`.
  That configuration change is a documented follow-on, not part of this plan.
- Agent mode for tools other than PHPStan. The contract and the fail-fast refusal are
  built for all of them; only the PHPStan lane implements it here.
- Replacing `--json`. That flag puts PHPStan's raw report on stdout for a caller that
  wants to pipe it. Agent mode keeps stdout terse and puts the report on disk.
- Reducing the log archive under `var/qa/phpstan_logs/`. Retention is a separate concern.

## Design

### The flag and its environment equivalent

`--agent-mode` on the command line, or `PHPQACI_AGENT_MODE` set to `1`/`true`. The two
are equivalent; the flag wins when they disagree only in the sense that either being on
turns the mode on. `QaConfigDto::$agentMode` carries the resolved value to the lanes.

Agent mode requires `-t <tool>`. A run with no tool selected, or with a phase runner,
is refused: agent mode is a single-lane, single-file reporting mode and there is no
sensible terse report for a whole pipeline.

### Per-tool capability and the fail-fast refusal

`ToolDefinitionDto` gains `supportsAgentMode`, defaulting to `false`, exactly as
`supportsJson` already works. `ArgumentsParser` refuses the run with a usage error
naming the supported tools when the selected tool does not declare support.

| Tool                        | Agent mode | Behaviour when selected                        |
| --------------------------- | ---------- | ---------------------------------------------- |
| `phpstan`                   | Supported  | Terse stdout, per-file JSON reports            |
| every other shipped lane    | Refused    | Usage error naming the supported tools, exit 1 |
| every phase runner (`all*`) | Refused    | Usage error, exit 1                            |
| no `-t` at all              | Refused    | Usage error, exit 1                            |

### Output routing

`--json` already proves the pattern: decoration is diverted so stdout carries only the
payload. Agent mode diverts decoration to a `NullOutput` instead of stderr, because the
daemon handler captures both streams. That alone removes the preamble, the mode
announcements, the phase and tool banners, the lock banners, the log-archive line and
the high-log-count warning, none of which need a change of their own.

### The JSON report

One file per analysed source file, at
`var/qa/phpstan-file-reports/<project-relative-path>.json`. The source path is appended
whole, extension included, so `src/Kernel.php` becomes
`var/qa/phpstan-file-reports/src/Kernel.php.json` and no two source files can collide.

```json
{
  "tool": "phpstan",
  "path": "src/Kernel.php",
  "status": "clean",
  "run_at": "2026-09-16T08:43:50+00:00",
  "exit_code": 0,
  "error_count": 0,
  "errors": [{ "line": 89, "message": "...", "identifier": "class.notFound", "tip": "..." }],
  "log_path": "/abs/path/var/qa/phpstan_logs/phpstan.json"
}
```

`status` is one of `clean`, `errors`, `crashed`. `line` is null for a file-level error
PHPStan reports without one. `identifier` and `tip` are null when PHPStan omits them.

An index at `var/qa/phpstan-file-reports/_index.json` lists every report the run wrote,
so stdout can stay three lines even when a whole-project run finds errors in fifty files.

### Defeating the stale report

PHPStan's JSON names only the files that have errors, so "no entry" cannot be
distinguished from "not analysed" without help. Before writing, the run **clears the
report tree for the scope it is about to analyse** — the specified path's subtree for a
`-p` run, the whole tree for a full run. Every report present afterwards was written by
this run. A `-p` run naming a single file then always writes that file's report, with
`error_count: 0` when it is clean, which is what makes a green re-run visibly erase the
previous red.

### Lock contention

`RunLock::acquire()` failing currently returns 1, the same code as findings, with the
explanation on the decoration stream that agent mode discards. In agent mode the
pipeline returns **exit 2** and prints one stdout line saying the lock is held and that
no analysis ran. No report is written: overwriting a file's last real result with a
lock-held placeholder would destroy the finding the agent was about to fix. A crash
returns **exit 3** and writes `status: "crashed"` for the specified path.

| Exit | Meaning                                    |
| ---- | ------------------------------------------ |
| 0    | Analysed, no errors                        |
| 1    | Analysed, errors found                     |
| 2    | Lock held by another run, nothing analysed |
| 3    | The tool crashed                           |

## Tasks

### Phase 1: The pipeline-level contract

- [x] ✅ **Task 1.1**: `ToolDefinitionDto::$supportsAgentMode`, `phpstan` declaring it, frozen in the registry characterisation test.
- [x] ✅ **Task 1.2**: `--agent-mode` parsing and the `PHPQACI_AGENT_MODE` environment read, with the fail-fast usage errors for no tool, a phase runner, and an unsupporting tool.
- [x] ✅ **Task 1.3**: `QaConfigDto::$agentMode` through `QaConfigBuilder`, and decoration routed to `NullOutput` in `QaApplication`.

### Phase 2: The report

- [x] ✅ **Task 2.1**: `FileReportDto` and `FileErrorDto`, serialising to the documented schema.
- [x] ✅ **Task 2.2**: `PhpstanJsonParser` mapping `--error-format=json` onto per-file findings, including file-level errors with no line.
- [x] ✅ **Task 2.3**: `FileReportWriter` — scope clearing, per-file writes, the index, and the path mapping that cannot collide.

### Phase 3: The PHPStan lane and the runner

- [x] ✅ **Task 3.1**: `PhpstanTool` agent-mode branch: JSON error format, report writing, the three-line stdout.
- [x] ✅ **Task 3.2**: `Pipeline` returning exit 2 with the lock-held stdout line in agent mode, and exit 3 for a crash.

### Phase 5: The PHPArkitect lane

- [x] ✅ **Task 5.1**: `ArkitectJsonParser` over the phar's native `--format=json`, anchored on `totalViolations` so the banner and progress bar cannot break the parse.
- [x] ✅ **Task 5.2**: `ClassFileLocator` — PHPArkitect reports classes, the report is keyed by file, and the autoloader is deliberately not consulted.
- [x] ✅ **Task 5.3**: `PhpArkitectTool` agent-mode branch, with the `_rules/<slug>.json` fallback for a class the class set cannot place.
- [x] ✅ **Task 5.4**: Capability on the registry entry; `docs/agent-mode.md` states that arch agent mode is whole-project because `-p` does not reach the lane.

### Phase 4: Documentation and proof

- [x] ✅ **Task 4.1**: `docs/agent-mode.md` — the flag, the environment variable, the capability table, the schema, the exit codes, and the `lint_on_edit` configuration a consumer applies.
- [x] ✅ **Task 4.2**: Prove it end to end from a consuming project; full `CI=true bin/qa` green in this repo.

## Success Criteria

- [x] `bin/qa --agent-mode -t phpstan -p src/Kernel.php` prints three lines or fewer and writes `var/qa/phpstan-file-reports/src/Kernel.php.json` with `error_count: 0`.
- [x] The same command against a file with errors reports `error_count > 0` and the same report path.
- [x] A green re-run over a previously red file overwrites the report with `error_count: 0`.
- [x] `bin/qa --agent-mode -t allCS` and `bin/qa --agent-mode -t phplint` both fail with a usage error naming the supported tools.
- [x] `PHPQACI_AGENT_MODE=1 bin/qa -t phpstan -p <file>` behaves identically to the flag.
- [x] A run blocked by the lock exits 2 and says so on stdout.
- [x] `CI=true bin/qa` exits 0 in this repository.
- [x] `bin/qa --agent-mode -t arch` prints three lines or fewer, reports each violation against the file declaring the violating class, and clears the tree on a green re-run.
- [x] A violating class the class set cannot place lands in `var/qa/arch-file-reports/_rules/<slug>.json` rather than being lost.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00012-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- `1ea85cb` — the plan, with the measured cost of the current per-edit output
- `6bea0d2` — the pure core: report DTOs, the JSON parser, the writer, the terse stdout
- `88c72a5` — the flag, the environment equivalent, the capability gate and the PHPStan lane
- `a15da62` — `docs/agent-mode.md`, the consumer wiring, and conformance to the harness
- [PR 34](https://github.com/LongTermSupport/php-qa-ci/pull/34) against `php8.5`, CI green on `a15da62`. Not merged: the Owner decides.
- Proven end to end from a consuming project. A clean file falls from 54 lines of stdout to 2, and a file with 108 findings from 419 to 3.
