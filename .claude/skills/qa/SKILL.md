---
name: qa
description: |
  🔄 PHP-QA-CI TOOL ORCHESTRATOR - Automatic run→fix→run cycling for php-qa-ci tools.

  **ONLY for php-qa-ci pipeline tools** (./bin/qa -t toolname)
  NOT for ad-hoc tool execution outside php-qa-ci.

  Use when user requests QA tools via php-qa-ci:
  - "run phpstan", "use stan skills"
  - "run tests", "run phpunit"
  - "run rector", "run cs fixer"
  - "run allStatic", "run allCS"

  **CRITICAL**: MUST cycle automatically until tool reports clean OR escalation needed.
  DO NOT stop after one fix to ask "what next?" - KEEP CYCLING.

  Supports all php-qa-ci tools via ./bin/qa -t {toolname}:
  - phpstan (static analysis) - has fixer agent
  - phpunit (tests) - has fixer agent
  - rector (refactoring) - self-fixing, re-run until clean
  - fixer (code style) - self-fixing, re-run until clean
  - infection (mutation testing) - no auto-fix
  - allStatic/allCs/allTests - meta groups

  Automatically detects which tools have fixers and invokes them.
allowed-tools: Task, Bash, Read, Glob
---

# PHP-QA-CI Tool Orchestrator Skill

**THIS SKILL IS EXCLUSIVELY FOR PHP-QA-CI PIPELINE TOOLS**

All tool execution MUST use: `./bin/qa -t {toolname}`
This ensures proper configuration, caching, and log rotation.

## PHP-QA-CI Pipeline Context

**What is php-qa-ci?**
- A comprehensive QA pipeline for PHP projects
- Installed as vendor package: `lts/php-qa-ci`
- Provides unified command: `./bin/qa`
- Orchestrates multiple tools with proper config/caching/logging

**All commands in this skill use php-qa-ci:**
- ✅ `export CI=true && ./bin/qa -t phpstan`
- ✅ `export CI=true && ./bin/qa -t unit`
- ✅ `export CI=true && ./bin/qa -t allStatic`
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

**Required Actions**:
- ✅ Detect which tool user wants from their request
- ✅ Run tool → Parse results → Fix if possible → Run again
- ✅ Keep cycling until clean OR escalation needed
- ✅ Report final summary only when done

## Tool Detection

Parse user request to identify tool:

| User Says | Tool Name | Has Fixer? | Fixer Skill/Agent |
|-----------|-----------|------------|-------------------|
| "run phpstan", "use stan skills" | `phpstan` | ✅ | phpstan-fixer |
| "run tests", "run phpunit" | `phpunit` | ✅ | phpunit-fixer |
| "run rector" | `rector` | ✅ Self-fixing | N/A - just re-run |
| "run cs fixer", "run fixer" | `fixer` | ✅ Self-fixing | N/A - just re-run |
| "run infection" | `infection` | ❌ | N/A - report only |
| "run allStatic" | `allStatic` | ✅ Mixed | Use component fixers |
| "run allCS" | `allCs` | ✅ Self-fixing | N/A - just re-run |
| "run allTests" | `allTests` | ✅ | phpunit-fixer |

## Universal Iteration Loop

```
INITIALIZE:
  - iteration = 0
  - max_iterations = 5
  - previous_errors = []
  - tool = detect_tool_from_user_request()

LOOP:
  1. Run tool via php-qa-ci pipeline:
     [Bash] export CI=true && ./bin/qa -t {tool} [-p {path}]
  2. Parse output/logs in var/qa/{tool}_logs/
  3. Check result:
     - CLEAN? → Report success, EXIT
     - CRASH? → Escalate, EXIT
     - ERRORS/FAILURES? → Continue to step 4
  4. Check escalation:
     - iteration >= max_iterations? → Escalate, EXIT
     - errors == previous_errors? → Escalate, EXIT
  5. Apply fix:
     - If tool has dedicated fixer → Invoke fixer skill
     - If tool is self-fixing → Continue (next run will fix)
     - If no fixer available → Report and ask user
  6. iteration++, previous_errors = current_errors
  7. IMMEDIATELY goto LOOP (no pause, no questions)
END
```

## Tool-Specific Strategies

### PHPStan (Static Analysis)
```
Run: export CI=true && ./bin/qa -t stan [-p path]
Log: var/qa/phpstan_logs/phpstan.log
Parse: python3 .claude/skills/phpstan-runner/scripts/parse-phpstan.py
Fixer: Launch phpstan-fixer agent via phpstan-fixer skill
Clean: "No errors" in output
```

### PHPUnit (Tests)
```
Run: export CI=true && ./bin/qa -t unit [-p path]
Log: var/qa/phpunit_logs/phpunit.junit.xml
Parse: python3 .claude/skills/phpunit-runner/scripts/parse-junit.py
Fixer: Launch phpunit-fixer agent via phpunit-fixer skill
Clean: "OK (X tests, Y assertions)" or "Tests: X, Assertions: Y, Failures: 0, Errors: 0"
```

### Rector (Refactoring)
```
Run: export CI=true && ./bin/qa -t rector
Log: stdout only
Self-fixing: Yes - re-run automatically applies fixes
Clean: "[OK] Rector is done!" or no files changed
Strategy: Just keep re-running until no changes
```

### PHP CS Fixer (Code Style)
```
Run: export CI=true && ./bin/qa -t fixer
Log: stdout only
Self-fixing: Yes - automatically fixes on each run
Clean: "Fixed all files" or exit code 0
Strategy: Just keep re-running until no changes
```

### Infection (Mutation Testing)
```
Run: export CI=true && ./bin/qa -t infection
Log: var/qa/infection/log.txt
No fixer: Cannot auto-fix mutations
Strategy: Run once, report MSI, ask user for next steps
```

### AllStatic (PHPStan + other static tools)
```
Run: export CI=true && ./bin/qa -t allStatic
Fixers: PHPStan has fixer, others report-only
Strategy: Cycle on PHPStan errors, report others
```

### AllCS (Rector + CS Fixer + linters)
```
Run: export CI=true && ./bin/qa -t allCs
Self-fixing: Yes - Rector and CS Fixer auto-fix
Strategy: Keep re-running until no changes
```

## Implementation Template

```
1. Detect tool from user request
   - Extract tool name (phpstan, phpunit, etc.)
   - Extract optional path (-p flag)

2. Run tool via Bash:
   export CI=true && ./bin/qa -t {tool} [-p {path}]

3. Capture exit code and output

4. Parse results based on tool type:
   - Check for clean completion
   - Count errors/failures
   - Extract error patterns

5. If not clean:
   - Check if fixer available
   - Invoke fixer OR re-run if self-fixing
   - IMMEDIATELY goto step 2

6. If clean:
   - Report success with summary
   - EXIT
```

## Example: PHPStan Workflow

```
User: "run phpstan"

Iteration 1:
  [Bash] export CI=true && ./bin/qa -t stan
  [Exit code 1 = errors found]
  [Parse] python3 .claude/skills/phpstan-runner/scripts/parse-phpstan.py
  [Result] 45 errors across 12 files
  [Action] Invoke phpstan-fixer skill
  [Fixer] Fixed 40 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 2:
  [Bash] export CI=true && ./bin/qa -t stan
  [Exit code 1 = errors found]
  [Parse] python3 .claude/skills/phpstan-runner/scripts/parse-phpstan.py
  [Result] 5 errors across 3 files
  [Action] Invoke phpstan-fixer skill
  [Fixer] Fixed 3 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 3:
  [Bash] export CI=true && ./bin/qa -t stan
  [Exit code 1 = errors found]
  [Parse] python3 .claude/skills/phpstan-runner/scripts/parse-phpstan.py
  [Result] 2 errors in 1 file
  [Action] Invoke phpstan-fixer skill
  [Fixer] Fixed 2 errors
  [AUTO-CONTINUE - NO PAUSE]

Iteration 4:
  [Bash] export CI=true && ./bin/qa -t stan
  [Exit code 0 = clean]
  [Result] No errors
  [DONE]

Report:
"✅ PHPStan analysis CLEAN after 4 iterations
  - Total errors fixed: 45
  - Final status: No errors
  - Log: var/qa/phpstan_logs/phpstan.TIMESTAMP.log"
```

## Example: Rector Workflow

```
User: "run rector"

Iteration 1:
  [Bash] export CI=true && ./bin/qa -t rector
  [Exit code 0, but files changed]
  [Result] Modified 23 files
  [Self-fixing tool - AUTO-CONTINUE]

Iteration 2:
  [Bash] export CI=true && ./bin/qa -t stan
  [Exit code 0, but files changed]
  [Result] Modified 5 files
  [Self-fixing tool - AUTO-CONTINUE]

Iteration 3:
  [Bash] export CI=true && ./bin/qa -t rector
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

## Fixer Skills Reference

This skill can invoke:
- `phpstan-fixer` skill → launches php-qa-ci_phpstan-fixer agent
- `phpunit-fixer` skill → launches php-qa-ci_phpunit-fixer agent

Both skills are in `.claude/skills/` directory.

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
./bin/qa -t stan [-p path]        # PHPStan
./bin/qa -t allStatic             # All static tools

# Testing
./bin/qa -t unit [-p path]        # PHPUnit
./bin/qa -t infection             # Mutation testing
./bin/qa -t allTests              # All test tools

# Code Standards
./bin/qa -t rector                # Rector refactoring
./bin/qa -t fixer                 # PHP CS Fixer
./bin/qa -t allCs                 # All CS tools

# Meta
./bin/qa                          # Full pipeline (all tools)
```
