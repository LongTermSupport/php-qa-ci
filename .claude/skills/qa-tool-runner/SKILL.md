---
name: qa-tool-runner
description: |
  Run any php-qa-ci tool or the full pipeline via generic agent delegation. Use for tools
  that lack a specialized runner skill (rector, fixer, infection, phplint, etc.)
  or to run the full unfiltered {bin}/qa pipeline.

  Delegates to:
  - php-qa-ci_qa-tool-runner agent (haiku) for individual tools
  - php-qa-ci_full-pipeline-runner agent (haiku) for full pipeline

  Self-fixing tools (rector, fixer) are automatically re-run until stable.
  Report-only tools (infection, phplint, etc.) run once and report.
allowed-tools: Task
---

# Generic QA Tool Runner Skill

## Bin Directory

`{bin}` throughout this document refers to the project's composer bin directory.
Runner agents detect this automatically via `composer config bin-dir` (default: `vendor/bin`).

This skill runs any php-qa-ci tool that doesn't have a specialized runner skill (phpstan-runner, phpunit-runner).

## Agent Delegation Strategy

This skill delegates to generic agents via the Task tool:

1. **php-qa-ci_qa-tool-runner agent (haiku)** - Runs any single `{bin}/qa -t {tool}`
2. **php-qa-ci_full-pipeline-runner agent (haiku)** - Runs full `{bin}/qa` (all tools)

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

1. Launch full pipeline runner agent:
   ```
   Use Task tool:
     description: "Run full QA pipeline"
     subagent_type: "php-qa-ci_full-pipeline-runner"
     prompt: "Run the full php-qa-ci pipeline: export CI=true && {bin}/qa"
   ```

2. Return comprehensive per-tool summary to orchestrator

### Self-Fixing Tool Cycling

For self-fixing tools (rector, fixer), cycle automatically:

```
Iteration 1: Run tool → files modified → AUTO-CONTINUE
Iteration 2: Run tool → files modified → AUTO-CONTINUE
Iteration 3: Run tool → no changes → DONE
```

Max 5 iterations. If still modifying files after 5 runs, escalate.

### Tool Classification

| Tool | Type | Auto-Cycle? |
|------|------|-------------|
| rector | Self-fixing | Yes - re-run until stable |
| fixer | Self-fixing | Yes - re-run until stable |
| phplint | Report-only | No - report and stop |
| infection | Report-only | No - report and stop |
| stricttypes | Report-only | No - report and stop |
| psr4 | Report-only | No - report and stop |
| composer | Report-only | No - report and stop |
| markdown | Report-only | No - report and stop |
| loc | Report-only | No - report and stop |
| allStatic | Mixed | No - report per-tool results |
| allCs | Self-fixing | Yes - re-run until stable |
| allTests | Mixed | No - report results |
| allLints | Report-only | No - report and stop |
| (full pipeline) | Mixed | No - report per-tool results |

## Escalation Triggers

- Self-fixing tool still modifying after 5 iterations
- Tool crashes (exit code > 1)
- Tool not found / not configured
