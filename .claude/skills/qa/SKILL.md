---
name: qa
description: |
  🔄 PHP-QA-CI TOOL ORCHESTRATOR - Automatic run→fix→run cycling for php-qa-ci tools.

  **ONLY for php-qa-ci pipeline tools** ({bin}/qa -t toolname)
  NOT for ad-hoc tool execution outside php-qa-ci.

  Use when user requests QA tools via php-qa-ci:
  - "run phpstan", "use stan skills"
  - "run tests", "run phpunit"
  - "run rector", "run cs fixer"
  - "run allStatic", "run allCS", "run full qa"

  **CRITICAL**: MUST cycle automatically until tool reports clean OR escalation needed.
  DO NOT stop after one fix to ask "what next?" - KEEP CYCLING.

  This skill is a shim: it forces the canonical procedure to be read, then follows it.
allowed-tools: Skill, Task
---

# PHP-QA-CI Tool Orchestrator

`{bin}` below is the project's composer bin directory (`composer config bin-dir`, default
`vendor/bin`); runner agents resolve it themselves.

## Step 0: read the procedure first (mandatory, every invocation)

Read both, in this order, before doing anything else:

1. The cycle itself — routing table, the loop, the escalation triggers, the report shape and
   the log locations — in `CLAUDE/qa-orchestration.md`
   (in a consuming project: `vendor/lts/php-qa-ci/CLAUDE/qa-orchestration.md`).
2. Which command to run and when, in `CLAUDE/prepush-verification.md`
   (in a consuming project: `vendor/lts/php-qa-ci/CLAUDE/prepush-verification.md`).
   **Neither this skill nor the procedure restates the commands**; that file is the single
   source of truth for them.

## Step 1: preflight

Launch the documentation-conflict check once per session:

```
[Task tool]
  description: "Check docs for conflicts"
  subagent_type: "php-qa-ci_docs-conflict-checker"
  prompt: "Check project documentation for php-qa-ci agent restrictions"
```

`❌ CONFLICTS DETECTED` → show the report and its suggested fix, and stop.
`✅ NO CONFLICTS DETECTED` → continue.

## Step 2: route and cycle

Detect the tool (and an optional `-p <path>`) from the request, then invoke the runner skill
the procedure's routing table names for it — `phpstan-runner`, `phpunit-runner` or
`qa-tool-runner` — through the Skill tool. **Never run the tool with Bash from this skill**;
the runner skills launch cheap agents so tool output stays out of the main context.

Cycle exactly as the procedure's loop says: runner → fixer (or re-run, for a self-fixing lane)
→ runner, with no pause, until clean or an escalation trigger fires.

## Step 3: report

Give the summaries the procedure's "Reporting" section describes: one per step, one at the
end, and an escalation report naming the trigger when stuck. A green claim is the full
unfiltered pipeline's exit code, never a single lane's.
