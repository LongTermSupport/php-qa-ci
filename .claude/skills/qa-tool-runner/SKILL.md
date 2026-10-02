---
name: qa-tool-runner
description: |
  Run any php-qa-ci tool, or the full pipeline. Use for tools that lack a specialized
  runner skill (rector, fixer, infection, phplint, etc.) or to run the full unfiltered
  {bin}/qa pipeline.

  - Individual tools: delegated to the php-qa-ci_qa-tool-runner agent (haiku)
  - Full pipeline: run by the main session itself in the background, never by a
    sub-agent; the php-qa-ci_full-pipeline-runner agent (haiku) only summarises its log

  Self-fixing tools (rector, fixer) are automatically re-run until stable.
  Report-only tools (infection, phplint, etc.) run once and report.
allowed-tools: Task, Bash
---

# Generic QA Tool Runner Skill

## Bin Directory

`{bin}` throughout this document refers to the project's composer bin directory.
Runner agents detect this automatically via `composer config bin-dir` (default: `vendor/bin`).

This skill runs any php-qa-ci tool that doesn't have a specialized runner skill (phpstan-runner, phpunit-runner).

## Agent Delegation Strategy

1. **php-qa-ci_qa-tool-runner agent (haiku)** - Runs any single `{bin}/qa -t {tool}`
2. **The main session itself** - Runs the full `{bin}/qa` (all tools); the
   **php-qa-ci_full-pipeline-runner agent (haiku)** reads its log and summarises it

## Workflow

### When running a specific tool (e.g., "run rector", "run fixer")

1. Launch generic runner agent:

   ```
   Use Task tool:
     description: "Run {tool} via qa pipeline"
     subagent_type: "php-qa-ci_qa-tool-runner"
     prompt: "Run the '{tool}' tool: export CI=true && {bin}/qa -t {tool}"
   ```

2. Parse agent output for status

3. If self-fixing tool and files were modified:

   - Re-run to check stability (max 5 iterations)
   - Stop when no more files are modified

4. If report-only tool:

   - Return results to orchestrator
   - No auto-fix available

### When running full pipeline ("run full qa", "run all tools")

The full pipeline is the coordinator's gate, so it is never delegated: a sub-agent's run
is denied wherever the hooks daemon's `subagent_full_qa_blocker` is configured
(`docs/hooks-daemon-full-qa-blocker.md`). Run it only after every editing agent has
finished.

1. Run it in the main session, in the background, with the output in a log:

   ```
   Use Bash tool (run_in_background: true):
     command: "CI=true {bin}/qa > var/qa/full-pipeline.log 2>&1"
   ```

2. Exit code 0 is a clean pipeline; report it. Exit 75 means another run held the lock
   and nothing was checked: wait for it and run again.

3. Any other exit code: have the log summarised rather than reading it here:

   ```
   Use Task tool:
     description: "Summarise full QA pipeline log"
     subagent_type: "php-qa-ci_full-pipeline-runner"
     prompt: "Summarise var/qa/full-pipeline.log (exit code {code})"
   ```

4. Return the per-tool summary to the orchestrator

### Self-Fixing Tool Cycling

For self-fixing tools (rector, fixer), cycle automatically:

```
Iteration 1: Run tool → files modified → AUTO-CONTINUE
Iteration 2: Run tool → files modified → AUTO-CONTINUE
Iteration 3: Run tool → no changes → DONE
```

Max 5 iterations. If still modifying files after 5 runs, escalate.

### Tool Classification

| Tool            | Type        | Auto-Cycle?                  |
| --------------- | ----------- | ---------------------------- |
| rector          | Self-fixing | Yes - re-run until stable    |
| fixer           | Self-fixing | Yes - re-run until stable    |
| phplint         | Report-only | No - report and stop         |
| infection       | Report-only | No - report and stop         |
| stricttypes     | Report-only | No - report and stop         |
| psr4            | Report-only | No - report and stop         |
| composer        | Report-only | No - report and stop         |
| markdown        | Report-only | No - report and stop         |
| loc             | Report-only | No - report and stop         |
| allStatic       | Mixed       | No - report per-tool results |
| allCs           | Self-fixing | Yes - re-run until stable    |
| allTests        | Mixed       | No - report results          |
| allLints        | Report-only | No - report and stop         |
| (full pipeline) | Mixed       | No - report per-tool results |

## Escalation Triggers

- Self-fixing tool still modifying after 5 iterations
- Tool crashes (exit code > 1)
- Tool not found / not configured
