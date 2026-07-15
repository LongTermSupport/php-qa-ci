# PHP-QA-CI Skills Deployment System Proposal

> **SUPERSEDED — historical proposal, NOT a current how-to.** This is a point-in-time
> design proposal. The shipped `scripts/deploy-skills.bash` diverged substantially from
> it: the skills/agents source lives under `.claude/skills/` and `.claude/agents/` (not the
> repo-root `php-qa-ci/skills/` this doc describes), and the real script additionally does
> hooks-daemon detection, `php-qa-ci__` hook-name migration + `settings.json` rewriting,
> git-hooks deployment, PHPStan rule scaffolding, and root-CLAUDE.md block injection — none
> of which this proposal anticipated. **Ownership model (authoritative):** deployed skills,
> agents and hooks are php-qa-ci-owned and are overwritten freely on every deploy; consumers
> must never hand-edit the deployed copies. Do not use this document as a guide to current
> behaviour.

**Date**: November 2025
**Status**: Proposal (superseded — see banner above)
**Target**: php-qa-ci v2.0 (post php8.4 branch merge)

## Executive Summary

Leverage php-qa-ci's composer-plugin architecture to automatically deploy and manage Claude Code Skills for QA tool execution and result parsing. This creates a zero-configuration QA assistant that understands full suite, path-specific, and file-based test running patterns.

## Background Research (November 2025)

### Claude Skills Architecture (Launched Oct 2025)

**Key Findings:**
- Skills are **model-invoked** - Claude autonomously decides when to use them
- Located in `.claude/skills/` (project) or `~/.claude/skills/` (personal)
- Each skill is a directory with `SKILL.md` containing YAML frontmatter + instructions
- Skills can include supporting files (scripts, templates, documentation)
- Token-efficient: Only frontmatter loaded initially (~dozens of tokens), full content loaded when invoked

**Frontmatter Format:**
```yaml
---
name: skill-name
description: What the skill does and when to use it (max 1024 chars)
allowed-tools: Read, Bash, Grep  # Optional: restrict tools
---
```

**Best Practices:**
- **Specificity**: Description must clearly state WHAT and WHEN
- **Focus**: One capability per skill
- **Discovery**: Claude matches user intent to description automatically

### Composer Plugin Capabilities

**Key Findings:**
- Composer plugins execute during install/update lifecycle
- `post-install-cmd` and `post-update-cmd` scripts available
- Plugins can write files to project root (common for scaffolding)
- Security: Plugin code runs with user permissions, must be trusted

**php-qa-ci Current State:**
- Already implements `ComposerPlugin` interface
- Has `PhiveUpdatePlugin` class
- Uses `post-install-cmd` hook for PHIVE setup
- Type: `composer-plugin` with auto-activation

## Proposed Architecture

### 1. Skills & Agents Directory Structure

```
php-qa-ci/
├── skills/                          # NEW: Skills templates (model-invoked entry points)
│   ├── phpunit-runner/              # Runs tests + fixes
│   │   ├── SKILL.md                 # Points to agent
│   │   ├── scripts/
│   │   │   └── parse-junit.py      # Moved from qaConfig/scripts
│   │   └── resources/
│   │       └── test-patterns.md
│   ├── phpunit-fixer/               # Finds logs + fixes (NO running)
│   │   ├── SKILL.md                 # Points to agent
│   │   └── scripts/
│   │       └── analyze-junit.py
│   ├── phpstan-runner/              # Runs analysis + fixes
│   │   ├── SKILL.md                 # Points to agent
│   │   ├── scripts/
│   │   │   └── parse-phpstan.py
│   │   └── resources/
│   │       └── error-patterns.md
│   ├── phpstan-fixer/               # Finds logs + fixes (NO running)
│   │   ├── SKILL.md                 # Points to agent
│   │   └── scripts/
│   │       └── analyze-phpstan.py
│   └── qa-pipeline/                 # FUTURE: Full suite runner
│       └── SKILL.md                 # Points to agent
├── agents/                          # NEW: Agent definitions (task executors)
│   ├── php-qa-ci_phpunit-runner.md  # Agent for running PHPUnit + parsing
│   ├── php-qa-ci_phpunit-fixer.md   # Agent for fixing PHPUnit failures
│   ├── php-qa-ci_phpstan-runner.md  # Agent for running PHPStan + parsing
│   └── php-qa-ci_phpstan-fixer.md   # Agent for fixing PHPStan errors
├── src/ComposerPlugin/
│   ├── PhiveUpdatePlugin.php       # EXISTING
│   └── SkillsDeployPlugin.php      # NEW: Skills & agents deployment
└── scripts/
    ├── deploy-skills.bash           # NEW: Skills & agents installation
    └── phive-install.bash           # EXISTING
```

**Key Architecture Concepts:**

- **Skills** (`.claude/skills/`) - Entry points invoked by Claude's model when matching user intent
- **Agents** (`.claude/agents/`) - Specialized task executors launched by skills using Task tool
- **Prefix Convention** - All agent files use `php-qa-ci_` prefix to avoid project conflicts
- **Delegation Pattern** - Skills delegate to agents, agents use appropriate model size

### 2. Skill Deployment Flow

```
composer install/update
  ↓
post-install-cmd hook
  ↓
scripts/deploy-skills.bash
  ↓
Copy skills/ → .claude/skills/
Copy agents/ → .claude/agents/
  ↓
Verify .gitignore excludes .claude/
  ↓
Report installed skills and agents
```

### 3. Sub-Agent Orchestration Pattern

**Critical Design Principle**: Skills are lightweight entry points that delegate to specialized agents using the Task tool.

#### Model Size Strategy

| Agent Type | Model Size | Purpose | When to Use |
|-----------|------------|---------|-------------|
| **Runner** | `haiku` (simple) | Execute tool + parse output + summarize | Running tests/analysis - simple, fast task |
| **Fixer** | `sonnet` (standard) | Analyze errors + implement fixes | Fixing most errors - standard complexity |
| **Stubborn Fixer** | `opus` (powerful) | Deep analysis + complex fixes | When standard fixer fails repeatedly |
| **Human Escalation** | N/A | Ask for help | When powerful model can't fix or user confirmation needed |

#### Run → Fix → Run Cycle

```
User: "Run tests and fix any failures"
  ↓
Skill: phpunit-runner (invoked by model)
  ↓
Task Tool: Launch php-qa-ci_phpunit-runner agent (haiku model)
  ↓
Agent: Run tests, parse output, summarize
  ↓
Agent Returns: "5 failures found. Log: var/qa/phpunit_logs/phpunit.junit.20251103-120621.xml"
  ↓
Skill: Launch php-qa-ci_phpunit-fixer agent (sonnet model)
  ↓
Agent: Parse log, group errors, fix first pattern
  ↓
Agent Returns: "Fixed 3 TypeError issues. Re-run to verify."
  ↓
Skill: Launch php-qa-ci_phpunit-runner agent (haiku model)
  ↓
Agent: Run tests again
  ↓
Agent Returns: "2 failures remain. Log: var/qa/phpunit_logs/phpunit.junit.20251103-121045.xml"
  ↓
Skill: Launch php-qa-ci_phpunit-fixer agent (sonnet model)
  ↓
Agent: Attempt to fix remaining errors
  ↓
Agent Returns: "Cannot fix AssertionFailure - test logic issue"
  ↓
Skill: Escalate to user or launch with opus model
```

#### Agent Communication Protocol

**Runner → Fixer Handoff:**
```markdown
SUMMARY: 5 test failures detected
LOG FILE: var/qa/phpunit_logs/phpunit.junit.20251103-120621.xml
ERROR BREAKDOWN:
  - TypeError: 3 occurrences
  - AssertionFailure: 2 occurrences
RECOMMENDATION: Fix TypeError pattern first (most common)
```

**Fixer → Runner Handoff:**
```markdown
FIXES APPLIED:
  - Fixed 3 TypeError issues in PaymentServiceTest
  - Modified constructor signatures to match interfaces
FILES CHANGED:
  - tests/Unit/PaymentServiceTest.php
  - src/Service/PaymentService.php
NEXT STEP: Re-run tests to verify fixes
```

**Stubborn Fix Escalation:**
```markdown
ESCALATION: Standard fixer unable to resolve after 2 attempts
ERROR: AssertionFailure in calculateTotal test
ROOT CAUSE: Test expects $100.00 but gets $99.99 (rounding issue)
ANALYSIS: Business logic question - is $99.99 correct or test expectation wrong?
REQUEST: Human review of rounding behavior in PaymentService::calculateTotal()
```

#### Agent File Format

Each agent file (`.claude/agents/php-qa-ci_{name}.md`) contains:

```markdown
# Agent: php-qa-ci_phpunit-runner

## Model
haiku (simple - fast execution for running tools)

## Purpose
Run PHPUnit tests, parse results, provide concise summary for fixer agent.

## Task
1. Estimate runtime (refuse full suite if >5min unless explicit)
2. Run tests with: `export CI=true && ./bin/qa -t unit [paths]`
3. Parse JUnit XML: `python3 .claude/skills/phpunit-runner/scripts/parse-junit.py`
4. Return summary with log location

## Output Format
SUMMARY: X failures, Y errors
LOG FILE: var/qa/phpunit_logs/phpunit.junit.TIMESTAMP.xml
ERROR BREAKDOWN: [grouped by type]
RECOMMENDATION: [what to fix first]

## Handoff
Pass summary + log location to php-qa-ci_phpunit-fixer agent.
```

### 4. Individual Skills Design

#### Skill: phpunit-runner

**Purpose**: Entry point for running PHPUnit tests. Delegates execution to specialized agents using Task tool with run→fix→run cycle.

**SKILL.md Frontmatter:**
```yaml
---
name: phpunit-runner
description: |
  Run PHPUnit tests and fix failures using intelligent agent delegation. Use when user requests to:
  - Run tests (full suite, specific path, or single file)
  - Fix failing tests
  - Analyze test failures
  - Check test coverage
  Delegates to runner agent (haiku) for execution and fixer agent (sonnet) for fixes.
  Automatically cycles between run and fix until tests pass or human intervention needed.
allowed-tools: Task
---
```

**SKILL.md Content Structure:**
```markdown
# PHPUnit Runner Skill

## Agent Delegation Strategy

This skill delegates to specialized agents via the Task tool:

1. **php-qa-ci_phpunit-runner agent (haiku model)** - Runs tests and parses results
2. **php-qa-ci_phpunit-fixer agent (sonnet model)** - Analyzes and fixes errors
3. **Escalation** - Uses opus model or asks human for stubborn issues

## Workflow

### When User Says: "Run tests"

1. Launch runner agent:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "haiku"
     prompt: "You are the php-qa-ci_phpunit-runner agent. Read .claude/agents/php-qa-ci_phpunit-runner.md for instructions. Run tests and return summary."
   ```

2. Receive runner output with log location

3. If failures detected:
   - Launch fixer agent:
     ```
     Use Task tool:
       subagent_type: "general-purpose"
       model: "sonnet"
       prompt: "You are the php-qa-ci_phpunit-fixer agent. Read .claude/agents/php-qa-ci_phpunit-fixer.md. Fix errors in log: {log_path}"
     ```

4. After fixes applied, re-run via runner agent

5. Repeat cycle until:
   - All tests pass → Success
   - Same errors persist 2+ times → Escalate to opus or human
   - User intervention needed → Ask user

### When User Says: "Fix the test failures"

1. Check if recent log exists in var/qa/phpunit_logs/

2. If log found:
   - Launch fixer agent directly with log path

3. If no log:
   - Launch runner agent first to generate log
   - Then launch fixer agent

### Escalation Triggers

Launch opus model or ask human when:
- Fixer agent reports "cannot fix" for same error 2+ times
- Business logic questions arise (test expectations vs code behavior)
- User explicitly requests explanation of failures

## Runner Agent Reference

The phpunit-runner agent (haiku model) handles:
- Runtime estimation (refuses full suite if >5min)
- Test execution with proper CI environment
- JUnit XML parsing
- Concise summary generation

See `.claude/agents/php-qa-ci_phpunit-runner.md` for agent implementation details.

## Fixer Agent Reference

The phpunit-fixer agent (sonnet model) handles:
- Log file discovery and parsing
- Error grouping by pattern
- Fix implementation
- Verification that fixes resolve issues

See `.claude/agents/php-qa-ci_phpunit-fixer.md` for agent implementation details.
```

**scripts/parse-junit.py**: (Moved from project, enhanced)
```python
#!/usr/bin/env python3
"""
PHPUnit JUnit XML Parser for Claude Skills

Automatically finds and parses most recent JUnit XML log.
Provides LLM-optimized output format.
"""
# ... existing parse-junit-logs.py content ...
# Enhanced with:
# - Auto-discovery of latest log
# - Condensed output for LLM consumption
# - Error pattern grouping
# - Actionable recommendations
```

#### Skill: phpunit-fixer

**Purpose**: Entry point for analyzing and fixing existing PHPUnit test failures without running tests. Delegates to fixer agent.

**SKILL.md Frontmatter:**
```yaml
---
name: phpunit-fixer
description: |
  Analyze existing PHPUnit test failure logs without running tests. Use when:
  - User says "fix the test failures" (after manually running tests)
  - User says "what tests are failing?"
  - User points to specific log file
  - Tests were run outside Claude's context
  Delegates to fixer agent (sonnet) to find logs, parse failures, and implement fixes.
  Does NOT execute tests - use phpunit-runner for that.
allowed-tools: Task
---
```

**SKILL.md Content Structure:**
```markdown
# PHPUnit Fixer Skill

## Agent Delegation Strategy

This skill delegates to the php-qa-ci_phpunit-fixer agent (sonnet model):

## Workflow

### When User Says: "Fix the test failures"

1. Launch fixer agent to find and analyze most recent log:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "sonnet"
     prompt: "You are the php-qa-ci_phpunit-fixer agent. Read .claude/agents/php-qa-ci_phpunit-fixer.md. Find most recent test log and fix failures."
   ```

2. Receive fixer output with:
   - Errors found and grouped by pattern
   - Fixes applied
   - Files modified

3. If no log found:
   - Suggest using phpunit-runner skill to generate log first

### When User Provides Specific Log Path

1. Launch fixer agent with explicit log path:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "sonnet"
     prompt: "You are the php-qa-ci_phpunit-fixer agent. Read .claude/agents/php-qa-ci_phpunit-fixer.md. Fix failures in log: {user_provided_path}"
   ```

### Escalation Triggers

Launch opus model or ask human when:
- Fixer agent reports business logic questions (test vs code expectations)
- Same error pattern persists after 2 fix attempts
- User asks for explanation rather than fixes

## Fixer Agent Reference

The phpunit-fixer agent (sonnet model) handles:
- Auto-discovery of most recent JUnit XML log
- Error parsing and pattern grouping
- Fix implementation for common patterns
- Reporting which files were changed

See `.claude/agents/php-qa-ci_phpunit-fixer.md` for agent implementation details.
```

**scripts/analyze-junit.py**:
```python
#!/usr/bin/env python3
"""
PHPUnit Log Analyzer (Fixer Skill)

Finds and analyzes existing JUnit XML logs.
Does NOT run tests.
"""
# Similar to parse-junit.py but:
# - Auto-discovers latest log
# - Provides more detailed fix suggestions
# - Groups errors by fixable patterns
# - Returns actionable next steps
```

#### Skill: phpstan-runner

**Purpose**: Entry point for running PHPStan static analysis. Delegates execution to specialized agents using Task tool with run→fix→run cycle.

**SKILL.md Frontmatter:**
```yaml
---
name: phpstan-runner
description: |
  Run PHPStan static analysis and fix errors using intelligent agent delegation. Use when user requests to:
  - Run static analysis
  - Fix PHPStan errors
  - Check code quality
  - Analyze type errors
  Delegates to runner agent (haiku) for execution and fixer agent (sonnet) for fixes.
  Automatically cycles between run and fix until analysis passes or human intervention needed.
allowed-tools: Task
---
```

**SKILL.md Content Structure:**
```markdown
# PHPStan Runner Skill

## Agent Delegation Strategy

This skill delegates to specialized agents via the Task tool:

1. **php-qa-ci_phpstan-runner agent (haiku model)** - Runs analysis and parses results
2. **php-qa-ci_phpstan-fixer agent (sonnet model)** - Analyzes and fixes errors
3. **Escalation** - Uses opus model or asks human for stubborn issues

## Workflow

### When User Says: "Run PHPStan"

1. Launch runner agent:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "haiku"
     prompt: "You are the php-qa-ci_phpstan-runner agent. Read .claude/agents/php-qa-ci_phpstan-runner.md for instructions. Run PHPStan and return summary."
   ```

2. Receive runner output with log location

3. If errors detected:
   - Launch fixer agent:
     ```
     Use Task tool:
       subagent_type: "general-purpose"
       model: "sonnet"
       prompt: "You are the php-qa-ci_phpstan-fixer agent. Read .claude/agents/php-qa-ci_phpstan-fixer.md. Fix errors in log: {log_path}"
     ```

4. After fixes applied, re-run via runner agent

5. Repeat cycle until:
   - Analysis passes → Success
   - Same errors persist 2+ times → Escalate to opus or human
   - User intervention needed → Ask user

### When User Says: "Fix the PHPStan errors"

1. Check if recent log exists in var/qa/phpstan_logs/

2. If log found:
   - Launch fixer agent directly with log path

3. If no log:
   - Launch runner agent first to generate log
   - Then launch fixer agent

### Escalation Triggers

Launch opus model or ask human when:
- Fixer agent reports "cannot fix" for same error 2+ times
- Architecture questions arise (design patterns, type hierarchies)
- User explicitly requests explanation of errors

## Runner Agent Reference

The phpstan-runner agent (haiku model) handles:
- PHPStan execution with proper configuration
- Log parsing for error patterns
- Concise summary generation

See `.claude/agents/php-qa-ci_phpstan-runner.md` for agent implementation details.

## Fixer Agent Reference

The phpstan-fixer agent (sonnet model) handles:
- Log file discovery and parsing
- Error grouping by pattern
- Fix implementation for common PHPStan issues
- Verification that fixes resolve issues

See `.claude/agents/php-qa-ci_phpstan-fixer.md` for agent implementation details.
```

**scripts/parse-phpstan.py**: (New, optimized for LLM)
```python
#!/usr/bin/env python3
"""
PHPStan Log Parser for Claude Skills

Parses PHPStan output logs (table format).
Provides LLM-optimized summary.
"""

import sys
import re
from pathlib import Path
from typing import Dict, List
from collections import defaultdict

def parse_phpstan_table(log_path: str) -> Dict:
    """Parse PHPStan table format output."""
    with open(log_path, 'r') as f:
        content = f.read()

    # Extract error lines
    errors = []
    current_file = None

    for line in content.split('\n'):
        # File header: " ------ -------..."
        if line.strip().startswith('------'):
            continue

        # File path line
        if line.startswith(' ') and line.strip() and not line.startswith('  '):
            current_file = line.strip()

        # Error line: "  123    Error message..."
        elif line.startswith('  ') and line.strip():
            match = re.match(r'\s+(\d+)\s+(.+)', line)
            if match:
                line_num = int(match.group(1))
                message = match.group(2).strip()
                errors.append({
                    'file': current_file,
                    'line': line_num,
                    'message': message
                })

    # Group by file and pattern
    by_file = defaultdict(list)
    for error in errors:
        by_file[error['file']].append(error)

    return {
        'total': len(errors),
        'by_file': dict(by_file),
        'patterns': group_by_pattern(errors)
    }

def group_by_pattern(errors: List[Dict]) -> Dict:
    """Group errors by pattern (first 50 chars of message)."""
    patterns = defaultdict(list)
    for error in errors:
        pattern = error['message'][:50]
        patterns[pattern].append(error)
    return dict(sorted(patterns.items(), key=lambda x: len(x[1]), reverse=True))

def main():
    # Auto-find latest log
    log_dir = Path('var/qa/phpstan_logs')
    if not log_dir.exists():
        print("No PHPStan logs found. Run: export CI=true && ./bin/qa -t stan")
        sys.exit(1)

    log_files = sorted(log_dir.glob('phpstan.*.log'), reverse=True)
    if not log_files:
        print("No PHPStan logs found.")
        sys.exit(1)

    log_path = str(log_files[0])
    results = parse_phpstan_table(log_path)

    print(f"PHPSTAN ANALYSIS")
    print("=" * 80)
    print(f"Total Errors: {results['total']}")
    print(f"Files with Errors: {len(results['by_file'])}")
    print()

    # Top 5 files
    print("TOP FILES BY ERROR COUNT")
    print("-" * 80)
    sorted_files = sorted(results['by_file'].items(), key=lambda x: len(x[1]), reverse=True)
    for file, errors in sorted_files[:5]:
        print(f"{file}: {len(errors)} errors")
    print()

    # Top error patterns
    print("COMMON ERROR PATTERNS")
    print("-" * 80)
    for pattern, occurrences in list(results['patterns'].items())[:5]:
        print(f"{pattern}...")
        print(f"  Occurrences: {len(occurrences)}")
        print(f"  Example: {occurrences[0]['file']}:{occurrences[0]['line']}")
        print()

    sys.exit(1 if results['total'] > 0 else 0)

if __name__ == '__main__':
    main()
```

#### Skill: phpstan-fixer

**Purpose**: Entry point for analyzing and fixing existing PHPStan errors without running analysis. Delegates to fixer agent.

**SKILL.md Frontmatter:**
```yaml
---
name: phpstan-fixer
description: |
  Analyze existing PHPStan error logs without running analysis. Use when:
  - User says "fix the phpstan errors" (after manually running PHPStan)
  - User says "what phpstan errors do I have?"
  - User points to specific log file
  - PHPStan was run outside Claude's context
  Delegates to fixer agent (sonnet) to find logs, parse errors, and implement fixes.
  Does NOT execute PHPStan - use phpstan-runner for that.
allowed-tools: Task
---
```

**SKILL.md Content Structure:**
```markdown
# PHPStan Fixer Skill

## Agent Delegation Strategy

This skill delegates to the php-qa-ci_phpstan-fixer agent (sonnet model):

## Workflow

### When User Says: "Fix the PHPStan errors"

1. Launch fixer agent to find and analyze most recent log:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "sonnet"
     prompt: "You are the php-qa-ci_phpstan-fixer agent. Read .claude/agents/php-qa-ci_phpstan-fixer.md. Find most recent PHPStan log and fix errors."
   ```

2. Receive fixer output with:
   - Errors found and grouped by pattern
   - Fixes applied
   - Files modified

3. If no log found:
   - Suggest using phpstan-runner skill to generate log first

### When User Provides Specific Log Path

1. Launch fixer agent with explicit log path:
   ```
   Use Task tool:
     subagent_type: "general-purpose"
     model: "sonnet"
     prompt: "You are the php-qa-ci_phpstan-fixer agent. Read .claude/agents/php-qa-ci_phpstan-fixer.md. Fix errors in log: {user_provided_path}"
   ```

### Escalation Triggers

Launch opus model or ask human when:
- Fixer agent reports architecture questions (type hierarchies, design patterns)
- Same error pattern persists after 2 fix attempts
- User asks for explanation rather than fixes

## Fixer Agent Reference

The phpstan-fixer agent (sonnet model) handles:
- Auto-discovery of most recent PHPStan log
- Error parsing and pattern grouping
- Fix implementation for common PHPStan patterns
- Reporting which files were changed

See `.claude/agents/php-qa-ci_phpstan-fixer.md` for agent implementation details.
```

**scripts/analyze-phpstan.py**:
```python
#!/usr/bin/env python3
"""
PHPStan Log Analyzer (Fixer Skill)

Finds and analyzes existing PHPStan logs.
Does NOT run PHPStan.
"""
# Similar to parse-phpstan.py but:
# - Auto-discovers latest log
# - Provides detailed fix suggestions for common patterns
# - Groups errors by fixability
# - Returns prioritized action plan
# - Includes code examples for fixes
```

#### Skill: qa-pipeline (Future)

**Purpose**: Orchestrate full QA pipeline (Rector → PHP-CS-Fixer → PHPStan → PHPUnit → Infection)

**SKILL.md Frontmatter:**
```yaml
---
name: qa-pipeline
description: |
  Run complete PHP QA pipeline with intelligent phase handling. Use when user requests to:
  - Run full QA checks
  - Apply all quality tools
  - Prepare code for commit/PR
  Orchestrates Rector, PHP-CS-Fixer, PHPStan, PHPUnit, and Infection in correct sequence.
allowed-tools: Bash, Read, Glob
---
```

### 4. Composer Plugin Implementation

**src/ComposerPlugin/SkillsDeployPlugin.php**:
```php
<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class SkillsDeployPlugin implements PluginInterface, EventSubscriberInterface
{
    private const SKILLS_SOURCE_DIR = 'skills';
    private const SKILLS_TARGET_DIR = '.claude/skills';

    public function activate(Composer $composer, IOInterface $io): void
    {
        // Plugin activation
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Plugin deactivation
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Plugin uninstall - optionally remove deployed skills
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'deploySkills',
            ScriptEvents::POST_UPDATE_CMD => 'deploySkills',
        ];
    }

    public function deploySkills(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();

        // Get project root
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectRoot = dirname($vendorDir);

        // Get php-qa-ci package path
        $qaciPath = $vendorDir . '/lts/php-qa-ci';

        if (!is_dir($qaciPath . '/' . self::SKILLS_SOURCE_DIR)) {
            $io->writeError('php-qa-ci skills directory not found');
            return;
        }

        $io->write('<info>Deploying php-qa-ci Claude Code Skills...</info>');

        // Execute deployment script
        $scriptPath = $qaciPath . '/scripts/deploy-skills.bash';
        $command = sprintf(
            'bash %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($qaciPath),
            escapeshellarg($projectRoot)
        );

        exec($command, $output, $exitCode);

        foreach ($output as $line) {
            $io->write('  ' . $line);
        }

        if ($exitCode === 0) {
            $io->write('<info>✓ Claude Code Skills deployed successfully</info>');
        } else {
            $io->writeError('✗ Skills deployment failed');
        }
    }
}
```

**scripts/deploy-skills.bash**:
```bash
#!/usr/bin/env bash

set -euo pipefail

QACI_PATH="${1:-}"
PROJECT_ROOT="${2:-}"

if [[ -z "$QACI_PATH" || -z "$PROJECT_ROOT" ]]; then
    echo "Usage: $0 <qaci-path> <project-root>"
    exit 1
fi

SKILLS_SOURCE="$QACI_PATH/skills"
SKILLS_TARGET="$PROJECT_ROOT/.claude/skills"
AGENTS_SOURCE="$QACI_PATH/agents"
AGENTS_TARGET="$PROJECT_ROOT/.claude/agents"

echo "Deploying Skills from: $SKILLS_SOURCE"
echo "                   to: $SKILLS_TARGET"
echo "Deploying Agents from: $AGENTS_SOURCE"
echo "                   to: $AGENTS_TARGET"

# Create .claude directories
mkdir -p "$SKILLS_TARGET"
mkdir -p "$AGENTS_TARGET"

# Copy each skill
for skill_dir in "$SKILLS_SOURCE"/*; do
    if [[ -d "$skill_dir" ]]; then
        skill_name=$(basename "$skill_dir")
        echo "  Installing skill: $skill_name"

        # Copy skill directory
        cp -r "$skill_dir" "$SKILLS_TARGET/$skill_name"

        # Make scripts executable
        if [[ -d "$SKILLS_TARGET/$skill_name/scripts" ]]; then
            chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.py 2>/dev/null || true
            chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.bash 2>/dev/null || true
        fi
    fi
done

# Copy each agent (just markdown files)
if [[ -d "$AGENTS_SOURCE" ]]; then
    for agent_file in "$AGENTS_SOURCE"/*.md; do
        if [[ -f "$agent_file" ]]; then
            agent_name=$(basename "$agent_file")
            echo "  Installing agent: $agent_name"
            cp "$agent_file" "$AGENTS_TARGET/$agent_name"
        fi
    done
fi

# Ensure .gitignore excludes .claude/
GITIGNORE="$PROJECT_ROOT/.gitignore"
if [[ -f "$GITIGNORE" ]] && ! grep -q "^\.claude/$" "$GITIGNORE"; then
    echo "" >> "$GITIGNORE"
    echo "# Claude Code personal configuration" >> "$GITIGNORE"
    echo ".claude/" >> "$GITIGNORE"
    echo "  Added .claude/ to .gitignore"
fi

echo "✓ Skills & Agents deployment complete"
echo ""
echo "Installed skills:"
ls -1 "$SKILLS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed agents:"
ls -1 "$AGENTS_TARGET" 2>/dev/null || echo "  (none)"
```

**Update composer.json**:
```json
{
  "extra": {
    "class": [
      "LTS\\PHPQA\\ComposerPlugin\\PhiveUpdatePlugin",
      "LTS\\PHPQA\\ComposerPlugin\\SkillsDeployPlugin"
    ]
  }
}
```

## Benefits

### For Users
- **Zero Configuration**: Skills & agents auto-deploy on `composer install/update`
- **Intelligent Assistance**: Claude delegates to specialized agents with appropriate model sizes
- **Automated Fix Cycles**: Run→fix→run cycle continues until tests pass
- **Cost Optimization**: Runner agents use haiku (cheap), fixer agents use sonnet (balanced)
- **Consistent Experience**: Same capabilities across all projects using php-qa-ci
- **Up-to-date**: Skills & agents update with php-qa-ci package

### For Developers
- **DRY Principle**: Parser scripts and agent logic maintained in one place
- **Version Control**: Skills & agents versioned with php-qa-ci
- **Team Consistency**: Everyone gets same Claude capabilities
- **Progressive Enhancement**: Skills & agents improve without project changes
- **Clear Separation**: Skills (entry points) vs Agents (executors)

### For QA Pipeline
- **Better Error Analysis**: LLM-optimized parsers provide actionable feedback
- **Faster Debugging**: Haiku model handles simple tasks quickly
- **Intelligent Fixing**: Sonnet model handles most fixes, opus for stubborn issues
- **Reduced Context**: Skills activate only when needed (token-efficient)
- **Sub-Agent Isolation**: Each agent runs in own context, returns concise summary
- **Extensible**: Easy to add new skills/agents for other tools

### For Cost Management
- **Model Size Strategy**:
  - 90% of runs use haiku (fastest, cheapest)
  - 90% of fixes use sonnet (balanced cost/capability)
  - <10% escalate to opus or human (only when necessary)
- **Token Efficiency**: Agent communication protocol uses concise summaries
- **Parallel Execution**: Multiple simple agents can run concurrently

## Implementation Roadmap

### Phase 1: Foundation (Week 1)
- [ ] Create `skills/` and `agents/` directory structure in php-qa-ci
- [ ] Implement `SkillsDeployPlugin` composer plugin
- [ ] Implement `deploy-skills.bash` script (copy skills + agents)
- [ ] Add plugin to composer.json extra.class
- [ ] Test basic skill and agent deployment

### Phase 2: PHPUnit Runner Skill + Agent (Week 2)
**Skill: phpunit-runner** (entry point):
- [ ] Write SKILL.md with Task tool delegation pattern
- [ ] Document run→fix→run cycle
- [ ] Document escalation triggers

**Agent: php-qa-ci_phpunit-runner** (haiku model):
- [ ] Move parse-junit-logs.py to skills/phpunit-runner/scripts/
- [ ] Enhance parser with runtime estimation
- [ ] Write agent .md file with execution instructions
- [ ] Test runner agent invocation via Task tool

**Integration**:
- [ ] Test skill → runner agent → fixer agent cycle
- [ ] Verify haiku model usage for runner
- [ ] Verify sonnet model usage for fixer

### Phase 3: PHPUnit Fixer Skill + Agent (Week 2)
**Skill: phpunit-fixer** (entry point):
- [ ] Write SKILL.md with Task tool delegation pattern
- [ ] Document log-only workflow (no test execution)

**Agent: php-qa-ci_phpunit-fixer** (sonnet model):
- [ ] Create analyze-junit.py parser
- [ ] Write agent .md file with fix patterns
- [ ] Add common error pattern fixes
- [ ] Test with existing logs

**Integration**:
- [ ] Test fixer skill → fixer agent direct invocation
- [ ] Test runner skill → fixer agent handoff
- [ ] Verify fixes trigger re-run via runner agent

### Phase 4: PHPStan Runner Skill + Agent (Week 3)
**Skill: phpstan-runner** (entry point):
- [ ] Write SKILL.md with Task tool delegation pattern
- [ ] Document run→fix→run cycle for PHPStan

**Agent: php-qa-ci_phpstan-runner** (haiku model):
- [ ] Create parse-phpstan.py parser
- [ ] Write agent .md file with execution instructions
- [ ] Test runner agent invocation

**Integration**:
- [ ] Test skill → runner agent → fixer agent cycle
- [ ] Verify model sizes (haiku for runner, sonnet for fixer)

### Phase 5: PHPStan Fixer Skill + Agent (Week 3)
**Skill: phpstan-fixer** (entry point):
- [ ] Write SKILL.md with Task tool delegation pattern
- [ ] Document log-only workflow

**Agent: php-qa-ci_phpstan-fixer** (sonnet model):
- [ ] Create analyze-phpstan.py parser
- [ ] Write agent .md file with common error patterns
- [ ] Test with existing logs

**Integration**:
- [ ] Test fixer skill → fixer agent direct invocation
- [ ] Test runner skill → fixer agent handoff
- [ ] Verify fixes trigger re-run

### Phase 6: QA Pipeline Skill + Agent (Week 4)
**FUTURE**: Full pipeline orchestration
- [ ] Design multi-tool orchestration (Rector → CS → PHPStan → PHPUnit)
- [ ] Write SKILL.md for pipeline skill
- [ ] Write agent .md for pipeline orchestration
- [ ] Test full pipeline scenarios with sub-agent delegation

### Phase 7: Polish & Documentation (Week 5)
- [ ] Update php-qa-ci README with skills & agents documentation
- [ ] Create SKILLS.md in php-qa-ci with skill catalog
- [ ] Create AGENTS.md with agent descriptions
- [ ] Add troubleshooting guide for sub-agent issues
- [ ] Document when to use which skill (runner vs fixer)
- [ ] Document model size strategy and escalation patterns
- [ ] Add examples of run→fix→run cycles

## Testing Strategy

### Unit Tests
- Test SkillsDeployPlugin event subscription
- Test deploy-skills.bash with mock directories
- Test parser scripts with fixture data

### Integration Tests
- Test full deployment in fresh project
- Test skill invocation scenarios
- Test skill updates on composer update

### User Acceptance Tests
- Test "run the tests" invokes phpunit-runner
- Test "fix phpstan errors" invokes phpstan-runner
- Test skill deactivation when not needed

## Security Considerations

1. **Script Execution**: Parser scripts run with user permissions (acceptable for dev tools)
2. **File Overwriting**: Skills only written to `.claude/` (user-controlled directory)
3. **Gitignore**: Automatically add `.claude/` to prevent accidental commits
4. **Verification**: Display deployed skills list after installation

## Migration Path

### For Existing Projects
1. Run `composer update lts/php-qa-ci`
2. Skills auto-deploy to `.claude/skills/`
3. Existing project files untouched
4. `.gitignore` updated automatically

### For New Projects
1. `composer require --dev lts/php-qa-ci`
2. Skills deployed during installation
3. Ready to use immediately

## Future Enhancements

1. **Skill Configuration**: Allow projects to disable specific skills
2. **Custom Skills**: Allow projects to add their own skills to `.claude/skills/`
3. **Skill Marketplace**: Publish skills to anthropics/skills for discovery
4. **Metrics**: Track skill invocation and success rates
5. **AI Training**: Use skill usage data to improve descriptions

## References

- [Claude Skills Documentation](https://docs.claude.com/en/docs/claude-code/skills)
- [Composer Plugin API](https://getcomposer.org/doc/articles/plugins.md)
- [Composer Scripts](https://getcomposer.org/doc/articles/scripts.md)
- [Claude Skills Announcement](https://www.anthropic.com/news/skills)

## Conclusion

This proposal leverages cutting-edge Claude Code Skills architecture (Nov 2025) with php-qa-ci's existing composer-plugin capabilities to create a seamless, intelligent QA assistant with sophisticated sub-agent orchestration.

**Key Innovations:**

1. **Skills as Entry Points**: Lightweight, model-invoked capabilities that delegate to specialized agents
2. **Agents as Executors**: Task-specific implementations with appropriate model sizes (haiku/sonnet/opus)
3. **Run→Fix→Run Cycle**: Automated iteration until tests pass or human intervention needed
4. **Cost Optimization**: 90% of work done by haiku (running) and sonnet (fixing) models
5. **Agent Isolation**: Each agent runs in own context, returns concise summary

**Deployment Strategy:**

- Skills and agents auto-deploy to `.claude/skills/` and `.claude/agents/` on `composer install/update`
- Agents use `php-qa-ci_` prefix to avoid conflicts with project-specific agents
- Skills reference agents via Task tool with explicit model size specification
- Zero configuration required from end users

**Progressive Rollout:**

The phased approach allows for incremental development and validation:
- **Weeks 1-2**: Foundation + PHPUnit skills/agents (immediate value)
- **Week 3**: PHPStan skills/agents (static analysis automation)
- **Week 4**: Full QA pipeline orchestration (future enhancement)
- **Week 5**: Documentation and polish

This architecture provides immediate productivity gains while establishing a foundation for future expansion to the complete QA pipeline, all while optimizing for cost through intelligent model size selection.
