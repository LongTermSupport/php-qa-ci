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
  - "run allStatic", "run allCS"

  **CRITICAL**: MUST cycle automatically until tool reports clean OR escalation needed.
  DO NOT stop after one fix to ask "what next?" - KEEP CYCLING.

  Supports all php-qa-ci tools via {bin}/qa -t {toolname}:
  - phpstan (static analysis) - has fixer agent
  - phpunit (tests) - has fixer agent
  - rector (refactoring) - self-fixing, re-run until clean
  - fixer (code style) - self-fixing, re-run until clean
  - infection (mutation testing) - no auto-fix
  - allStatic/allCs/allTests - meta groups

  Automatically detects which tools have fixers and invokes them.

  **PERFORMS PREFLIGHT CHECK**: Invokes docs-conflict-checker agent to ensure
  project documentation doesn't conflict with skills/agents system requirements.
allowed-tools: Skill, Task
---

# PHP-QA-CI Tool Orchestrator Skill

**THIS SKILL IS EXCLUSIVELY FOR PHP-QA-CI PIPELINE TOOLS**

All tool execution MUST use the `qa` binary via the project's composer bin directory.
This ensures proper configuration, caching, and log rotation.

## Bin Directory

`{bin}` throughout this document refers to the project's composer bin directory.
Runner agents detect this automatically via `composer config bin-dir` (default: `vendor/bin`).
All command examples below use `{bin}/qa` — agents substitute the real path at runtime.

## ⚠️ STEP 0: PREFLIGHT CHECK - MUST RUN FIRST

**BEFORE doing anything else, check for documentation conflicts**

Launch the docs-conflict-checker agent:

```
[Task tool]
  description: "Check docs for conflicts"
  subagent_type: "php-qa-ci_docs-conflict-checker"
  prompt: "Check project documentation for php-qa-ci agent restrictions"
```

**Parse checker output:**

If checker reports `❌ CONFLICTS DETECTED`:
- Display the full conflict report to user
- Show the suggested fix
- **STOP** - Do not proceed with QA workflow
- Offer to provide complete replacement text if user wants it

If checker reports `✅ NO CONFLICTS DETECTED`:
- Proceed to Step 1 (Tool Detection)

## PHP-QA-CI Pipeline Context

**What is php-qa-ci?**
- A comprehensive QA pipeline for PHP projects
- Installed as vendor package: `lts/php-qa-ci`
- Provides unified command: `{bin}/qa`
- Orchestrates multiple tools with proper config/caching/logging

**All commands in this skill use php-qa-ci:**
- ✅ `export CI=true && {bin}/qa -t phpstan`
- ✅ `export CI=true && {bin}/qa -t unit`
- ✅ `export CI=true && {bin}/qa -t allStatic`
- ❌ `vendor/bin/phpstan analyse` (bypasses pipeline)
- ❌ `vendor/bin/phpunit` (bypasses pipeline)

**Why use php-qa-ci instead of tools directly?**
- Consistent configuration across projects
- Automatic log rotation (keeps last 10 runs)
- Proper caching and temp directories
- Integration with project config in `qaConfig/`
- Parallel processing where supported

## 🚨 CRITICAL INSTRUCTION - READ FIRST

**YOU MUST CYCLE AUTOMATICALLY WITHOUT STOPPING**

This skill runs php-qa-ci tools and automatically fixes issues until clean.

**Forbidden Actions**:
- ❌ Running once, fixing once, then asking "Should I run again?"
- ❌ Stopping to report status between iterations
- ❌ Waiting for user permission between cycles
- ❌ **CRITICAL**: Using Bash tool to run commands (burns main context tokens!)

**Required Actions**:
- ✅ Detect which tool user wants from their request
- ✅ **Invoke runner SKILLS** (phpstan-runner, phpunit-runner) NOT Bash
- ✅ Runner skills launch cheap agents to execute tools
- ✅ Keep cycling: run skill → fixer skill → run skill...
- ✅ Report final summary only when done

**TOKEN EFFICIENCY - CRITICAL**:
- This skill ONLY invokes other skills via Skill tool
- Runner skills (phpstan-runner, phpunit-runner) launch cheap haiku agents
- Agents run Bash commands in cheap agent context
- This keeps expensive tool output OUT of main context

## Tool Detection

Parse user request to identify tool:

| User Says | Tool Name | Runner | Has Fixer? | Fixer Skill/Agent |
|-----------|-----------|--------|------------|-------------------|
| "run phpstan", "use stan skills" | `phpstan` | phpstan-runner skill | ✅ | phpstan-fixer |
| "run tests", "run phpunit" | `phpunit` | phpunit-runner skill | ✅ | phpunit-fixer |
| "run rector" | `rector` | qa-tool-runner skill | ✅ Self-fixing | N/A - just re-run |
| "run cs fixer", "run fixer" | `fixer` | qa-tool-runner skill | ✅ Self-fixing | N/A - just re-run |
| "run infection" | `infection` | qa-tool-runner skill | ❌ | N/A - report only |
| "run phplint", "run lint" | `phplint` | qa-tool-runner skill | ❌ | N/A - report only |
| "run strict types" | `stricttypes` | qa-tool-runner skill | ❌ | N/A - report only |
| "run psr4" | `psr4` | qa-tool-runner skill | ❌ | N/A - report only |
| "run composer check" | `composer` | qa-tool-runner skill | ❌ | N/A - report only |
| "run allStatic" | `allStatic` | qa-tool-runner skill | ✅ Mixed | Use component fixers |
| "run allCS" | `allCs` | qa-tool-runner skill | ✅ Self-fixing | N/A - just re-run |
| "run allTests" | `allTests` | phpunit-runner skill | ✅ | phpunit-fixer |
| "run allLints" | `allLints` | qa-tool-runner skill | ❌ | N/A - report only |
| "run full qa", "run all", "run qa" | (full pipeline) | qa-tool-runner skill | ✅ Mixed | Per-tool fixers |

## Universal Iteration Loop

```
INITIALIZE:
  - iteration = 0
  - max_iterations = 5
  - previous_errors = []
  - tool = detect_tool_from_user_request()

LOOP:
  1. Run tool via appropriate runner SKILL (NOT Bash!):
     - phpstan → [Skill] Invoke phpstan-runner skill
     - phpunit/allTests → [Skill] Invoke phpunit-runner skill
     - All other tools → [Skill] Invoke qa-tool-runner skill
     - Full pipeline (no -t) → [Skill] Invoke qa-tool-runner skill (full pipeline mode)
     - Runner skill launches cheap haiku agent
     - Agent runs: export CI=true && {bin}/qa [-t {tool}] [-p {path}]
     - Agent parses logs and returns summary
  2. Parse runner skill output:
     - CLEAN? → Report success, EXIT
     - CRASH? → Escalate, EXIT
     - ERRORS/FAILURES? → Continue to step 3
  3. Check escalation:
     - iteration >= max_iterations? → Escalate, EXIT
     - errors == previous_errors? → Escalate, EXIT
  4. Apply fix:
     - If tool has dedicated fixer (phpstan/phpunit) → Invoke fixer SKILL
     - If self-fixing tool (rector/fixer) → Re-run via runner (tool fixes itself)
     - If no fixer available → Report and ask user
  5. iteration++, previous_errors = current_errors
  6. IMMEDIATELY goto LOOP (no pause, no questions)
END

**KEY**: NEVER use Bash tool in this skill - ALWAYS invoke runner skills
```

## 📊 Step Summaries - CRITICAL

**After EVERY step, provide a clear summary to the user:**

### After Runner Execution
```markdown
## 🔄 Iteration X - Runner Results

**Tool**: PHPStan/PHPUnit/etc
**Exit Code**: X
**Status**: ✅ Clean | ⚠️ Errors Found | ❌ Crashed

**Quick Stats**:
- Total Errors/Failures: XX
- Files Affected: YY
- Most Common Pattern: [pattern name] (ZZ occurrences)

**Next Action**: Launching fixer | Re-running | Complete
```

### After Fixer Execution
```markdown
## 🔧 Iteration X - Fixer Results

**Fixes Applied**: XX
**Files Modified**: YY
**Patterns Fixed**:
- Pattern A: X fixes
- Pattern B: Y fixes

**Next Action**: Re-running tool to verify fixes
```

### Final Summary (When Clean)
```markdown
## ✅ QA Pipeline Complete

**Tool**: [toolname]
**Total Iterations**: X
**Total Fixes Applied**: YY
**Final Status**: All checks passing

**Log File**: `var/qa/{tool}_logs/{tool}.TIMESTAMP.log`

Pipeline succeeded! 🎉
```

### Escalation Summary (When Stuck)
```markdown
## ⚠️ Escalation Needed

**Tool**: [toolname]
**Iterations Attempted**: X
**Remaining Errors**: YY
**Issue**: Same errors persisting | Max iterations reached | Manual intervention required

**Remaining Error Patterns**:
- Pattern A: X occurrences (requires architecture changes)
- Pattern B: Y occurrences (business logic questions)

**Recommendation**: Human review required for remaining issues
```

## Tool-Specific Strategies

### PHPStan (Static Analysis)
```
[Skill] Invoke phpstan-runner skill
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t stan [-p path]
  → Agent parses: var/qa/phpstan_logs/phpstan.log
  → Returns summary to main context
[Skill] If errors → Invoke phpstan-fixer skill
  → Fixer skill launches sonnet agent
  → Agent implements fixes
Clean: "No errors" in output
```

### PHPUnit (Tests)
```
[Skill] Invoke phpunit-runner skill
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t unit [-p path]
  → Agent parses: var/qa/phpunit_logs/phpunit.junit.xml
  → Returns summary to main context
[Skill] If failures → Invoke phpunit-fixer skill
  → Fixer skill launches sonnet agent
  → Agent implements fixes
Clean: "OK (X tests, Y assertions)" or "Tests: X, Failures: 0, Errors: 0"
```

### Rector (Refactoring)
```
[Skill] Invoke qa-tool-runner skill with tool=rector
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t rector
Self-fixing: Yes - re-run automatically applies fixes
Clean: "[OK] Rector is done!" or no files changed
Strategy: qa-tool-runner auto-cycles until stable
```

### PHP CS Fixer (Code Style)
```
[Skill] Invoke qa-tool-runner skill with tool=fixer
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t fixer
Self-fixing: Yes - automatically fixes on each run
Clean: "Fixed all files" or exit code 0
Strategy: qa-tool-runner auto-cycles until stable
```

### Infection (Mutation Testing)
```
[Skill] Invoke qa-tool-runner skill with tool=infection
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t infection
No fixer: Cannot auto-fix mutations
Strategy: Run once, report MSI, ask user for next steps
```

### Other Tools (phplint, psr4, etc.)
```
[Skill] Invoke qa-tool-runner skill with tool={toolname}
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t {toolname}
Report-only: No auto-fix
Strategy: Run once, report results
```

### AllStatic (PHPStan + other static tools)
```
[Skill] Invoke qa-tool-runner skill with tool=allStatic
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t allStatic
Fixers: PHPStan has fixer, others report-only
Strategy: If PHPStan errors found, invoke phpstan-fixer then re-run
```

### AllCS (Rector + CS Fixer + linters)
```
[Skill] Invoke qa-tool-runner skill with tool=allCs
  → Runner skill launches haiku agent
  → Agent runs: export CI=true && {bin}/qa -t allCs
Self-fixing: Yes - Rector and CS Fixer auto-fix
Strategy: qa-tool-runner auto-cycles until stable
```

### Full Pipeline (all tools)
```
[Skill] Invoke qa-tool-runner skill in full pipeline mode
  → Runner skill launches php-qa-ci_full-pipeline-runner agent (haiku)
  → Agent runs: export CI=true && {bin}/qa
  → Returns per-tool pass/fail summary table
Strategy: Run once, report comprehensive results, suggest fixing failed tools individually
```

## Implementation Template

```
1. Detect tool from user request
   - Extract tool name (phpstan, phpunit, rector, fixer, etc.)
   - Extract optional path (-p flag)
   - Detect "full pipeline" / "run all" / "run qa" (no -t flag)

2. Route to appropriate runner SKILL (NOT Bash!):
   - phpstan → [Skill] phpstan-runner
   - phpunit, allTests → [Skill] phpunit-runner
   - All other individual tools → [Skill] qa-tool-runner (with tool name)
   - Full pipeline → [Skill] qa-tool-runner (full pipeline mode)

   Runner skill launches cheap haiku agent, returns summary to main context

3. Parse runner skill output:
   - Check for clean completion
   - Count errors/failures
   - Extract error patterns

4. If not clean:
   - phpstan → [Skill] phpstan-fixer skill
   - phpunit → [Skill] phpunit-fixer skill
   - Self-fixing tool (rector/fixer) → Re-run via same runner skill
   - Report-only tool → Report results to user, EXIT
   - Full pipeline → Report per-tool results, suggest fixing individually
   - IMMEDIATELY goto step 2 (if fix was applied)

5. If clean:
   - Report success with summary
   - EXIT
```

## Example: PHPStan Workflow

```
User: "run phpstan"

Iteration 1:
  [Skill] Invoke phpstan-runner skill
    → Runner launches haiku agent (cheap!)
    → Agent runs: export CI=true && {bin}/qa -t stan
    → Agent parses log, returns summary
  [Result] 45 errors across 12 files
  [Skill] Invoke phpstan-fixer skill
    → Fixer launches sonnet agent (cheap!)
    → Agent implements fixes
  [Result] Fixed 40 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 2:
  [Skill] Invoke phpstan-runner skill
    → Agent runs analysis again
  [Result] 5 errors across 3 files
  [Skill] Invoke phpstan-fixer skill
    → Agent implements fixes
  [Result] Fixed 3 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 3:
  [Skill] Invoke phpstan-runner skill
    → Agent runs analysis again
  [Result] 2 errors in 1 file
  [Skill] Invoke phpstan-fixer skill
    → Agent implements fixes
  [Result] Fixed 2 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 4:
  [Skill] Invoke phpstan-runner skill
    → Agent runs analysis again
  [Result] No errors - CLEAN
  [DONE]

Report:
"✅ PHPStan analysis CLEAN after 4 iterations
  - Total errors fixed: 45
  - Final status: No errors
  - All tool execution in cheap agent context (saved main context tokens!)
  - Log: var/qa/phpstan_logs/phpstan.TIMESTAMP.log"
```

## Example: Rector Workflow

```
User: "run rector"

Iteration 1:
  [Bash] export CI=true && {bin}/qa -t rector
  [Exit code 0, but files changed]
  [Result] Modified 23 files
  [Self-fixing tool - AUTO-CONTINUE]

Iteration 2:
  [Bash] export CI=true && {bin}/qa -t stan
  [Exit code 0, but files changed]
  [Result] Modified 5 files
  [Self-fixing tool - AUTO-CONTINUE]

Iteration 3:
  [Bash] export CI=true && {bin}/qa -t rector
  [Exit code 0, no files changed]
  [DONE]

Report:
"✅ Rector complete after 3 iterations
  - Total files modified: 28
  - Final status: No changes needed"
```

## Escalation Triggers

Stop cycling and ask user when:
1. **Max iterations reached (5)** - Tool keeps finding issues
2. **Same errors persist 2+ iterations** - Fixer can't resolve
3. **Tool crashes repeatedly** - Configuration issue
4. **No fixer available** - Manual intervention needed (e.g., infection mutations)
5. **Runtime estimation >5min** - Need user approval (PHPUnit)

## Log Locations

All tools write logs to `var/qa/{tool}_logs/`:
- PHPStan: `var/qa/phpstan_logs/phpstan.log`
- PHPUnit: `var/qa/phpunit_logs/phpunit.junit.xml`
- Infection: `var/qa/infection/log.txt`
- Others: Check stdout/stderr

Use log rotation - each run creates timestamped copy, keeps last 10.

## Runner & Fixer Skills Reference

This skill can invoke:

**Specialized runners** (for tools with dedicated agents):
- `phpstan-runner` skill → launches php-qa-ci_phpstan-runner agent (haiku)
- `phpunit-runner` skill → launches php-qa-ci_phpunit-runner agent (haiku)

**Generic runner** (for all other tools):
- `qa-tool-runner` skill → launches php-qa-ci_qa-tool-runner agent (haiku) for individual tools
- `qa-tool-runner` skill → launches php-qa-ci_full-pipeline-runner agent (haiku) for full pipeline

**Fixers** (for tools with dedicated fix agents):
- `phpstan-fixer` skill → launches php-qa-ci_phpstan-fixer agent (sonnet)
- `phpunit-fixer` skill → launches php-qa-ci_phpunit-fixer agent (sonnet)

All skills are in `.claude/skills/` directory.
All agents are in `.claude/agents/` directory.

## Important Notes

1. **This is THE primary QA skill** - Use it for all QA tool requests
2. **Always cycle automatically** - Never stop to ask between iterations
3. **Track iterations** - Prevent infinite loops
4. **Use CI mode** - Always set `export CI=true` to prevent interactive prompts
5. **Path support** - Allow `-p path` for targeted runs
6. **Report only when done** - No interim questions

## Tool Command Reference

Quick reference for all php-qa-ci tools:

```bash
# Static Analysis
{bin}/qa -t stan [-p path]        # PHPStan
{bin}/qa -t allStatic             # All static tools

# Testing
{bin}/qa -t unit [-p path]        # PHPUnit
{bin}/qa -t infection             # Mutation testing
{bin}/qa -t allTests              # All test tools

# Code Standards
{bin}/qa -t rector                # Rector refactoring
{bin}/qa -t fixer                 # PHP CS Fixer
{bin}/qa -t allCs                 # All CS tools

# Meta
{bin}/qa                          # Full pipeline (all tools)
```
