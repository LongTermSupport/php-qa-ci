# PHP-QA-CI Claude Code Hooks

This directory contains Claude Code hooks that provide guardrails and automation for development workflows.

## What Are Claude Code Hooks?

Claude Code hooks are Python scripts that intercept and can modify tool calls made by Claude. They run before (PreToolUse) or after (PostToolUse) Claude executes tools like Write, Edit, or Bash.

## Available Hooks

### 1. php-qa-ci__auto-continue.py ✅ RECOMMENDED
**Purpose**: Reduces confirmation prompts by automatically detecting when Claude asks "would you like me to..." and you respond with "yes".

**How it works**:
- Monitors `UserPromptSubmit` event
- Detects common confirmation patterns in Claude's last message
- If you respond with "yes", "continue", "proceed", etc., it injects auto-continue context
- Eliminates back-and-forth confirmation cycles

**When to use**: Deploy by default - no configuration needed, no downside.

---

### 2. php-qa-ci__prevent-destructive-git.py ✅ CRITICAL
**Purpose**: Blocks git commands that permanently destroy uncommitted changes.

**Blocked commands**:
- `git reset --hard` - Destroys all uncommitted changes
- `git checkout <path>` - Overwrites files with repo version
- `git checkout .` - Overwrites all files
- `git restore --worktree` - Overwrites working directory
- `git clean -f` - Deletes untracked files
- `git stash drop/clear` - Destroys stashed changes

**Allowed commands**:
- `git reset --soft` - Safe, keeps changes
- `git checkout <branch>` - Safe, switches branches
- `git restore --staged` - Safe, only unstages

**When to use**: Deploy by default - critical safety feature, prevents data loss.

---

### 3. php-qa-ci__discourage-git-stash.py ⚠️ OPTIONAL
**Purpose**: Blocks git stash usage (with escape hatch) to encourage better workflows.

**Why block stash?**:
- Stashes can be forgotten and lost
- Not part of git graph (can become orphaned)
- `git stash drop/clear` permanently destroys work
- Usually indicates workflow problems

**Better alternatives**:
- `git commit -m "WIP: description"` - Proper version control
- `git checkout -b experiment/feature` - New branch for experiments
- `git worktree add` - Parallel work isolation

**Escape hatch**: Include phrase "I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION" in command.

**When to use**: Optional - deploy if team wants to discourage git stash usage.

---

### 4. php-qa-ci__block-plan-time-estimates.py ⚠️ OPTIONAL
**Purpose**: Prevents time estimates and completion dates from being written to plan documents.

**Philosophy**: Plans should focus on WHAT and HOW, never WHEN or how long.

**Blocked patterns**:
- "Estimated Effort: X hours"
- "Target Completion: YYYY-MM-DD"
- "Timeline" sections with durations
- Phase estimates ("Phase 1: 2 hours")

**Configuration**:
```bash
# Customize which directories are checked
export PLAN_DIRECTORY_PATTERNS="CLAUDE/Plan/,docs/plans/,.claude/plans/"
```

**Default checked paths**:
- `CLAUDE/Plan/`
- `CLAUDE/plan/`
- `.claude/plans/`
- `docs/plans/`

**Escape hatch**: Add comment in markdown:
```markdown
<!-- \*\*Estimated [^:]*\*\*: .*?(?:hours?|minutes?|days?|weeks?) match is a false positive for time estimate blocking hook -->
```

**When to use**: Optional - deploy if you want to enforce "no time estimates" in plans.

---

### 5. php-qa-ci__validate-claude-readme-content.py ⚠️ OPTIONAL
**Purpose**: Ensures CLAUDE.md and README.md contain instructions, not logs/research/summaries.

**Blocked patterns**:
- Implementation logs ("Created X", "Modified Y")
- Status indicators ("✅ Complete", "🟢 Working")
- Timestamps and dates
- LLM summary patterns ("## Summary", "## Key Points")
- Test results and QA output
- File listings
- "All done" completion indicators

**Allowed content**:
- Clear, actionable instructions
- Context about directory/module purpose
- Guidelines and conventions
- Configuration notes and gotchas

**Philosophy**: Documentation should instruct OTHERS how to use something, not document what you did.

**When to use**: Optional - deploy if you want to enforce instruction-only content in CLAUDE.md/README.md.

---

### 6. php-qa-ci__enforce-markdown-organization.py ⚠️ OPTIONAL
**Purpose**: Enforces strict organization rules for where *.md files can be written.

**Philosophy**: Prevents documentation from littering the filesystem. Everything has its place.

**Allowed locations**:
- `CLAUDE/Plan/*` or `CLAUDE/plan/*` - Plan-specific documentation (convention)
- `CLAUDE/` (root only) - Generic LLM documentation
- `docs/` - Human-facing documentation
- `untracked/` - Temporary ad-hoc docs
- `.claude/agents/*.md` - Agent definitions
- `.claude/skills/*/*.md` - Skills documentation (warns for non-SKILL.md files)
- `CLAUDE.md` / `README.md` - Allowed anywhere

**Blocked locations** (except CLAUDE.md/README.md):
- `.claude/hooks/` - No docs, scripts only
- Root directory - Prevents clutter

**Skills documentation**: Allows any .md files in `.claude/skills/*/` but warns if not SKILL.md. This lets you add implementation notes, guides, etc.

**Configuration**:
```bash
# Customize project root detection
export PROJECT_ROOT_INDICATORS="composer.json,package.json,.git"
```

**When to use**: Optional - deploy if you want to enforce organized documentation structure.

**Note**: Supports common conventions (CLAUDE/, docs/, untracked/). If this hook blocks your documentation incorrectly, raise it with a human to review the project's structure.

---

## Deployment

### Automatic Deployment (Recommended)

Use the deployment script to install all hooks, agents, and skills:

```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

This will:
1. Copy all hooks to `.claude/hooks/`
2. Make them executable
3. Register them in `.claude/settings.json`

### Manual Deployment

If you want selective deployment:

```bash
# Copy specific hooks
cp vendor/lts/php-qa-ci/.claude/hooks/php-qa-ci__auto-continue.py .claude/hooks/
cp vendor/lts/php-qa-ci/.claude/hooks/php-qa-ci__prevent-destructive-git.py .claude/hooks/
chmod +x .claude/hooks/*.py

# Register in .claude/settings.json (manual JSON editing required)
```

### Deployment in New Projects

Add to project setup documentation:

```bash
# After composer install
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

---

## Hook Architecture

### Hook Format

All hooks use the **new format** (JSON stdin/stdout):

```python
#!/usr/bin/env python3
import json
import sys

def allow_and_exit():
    """Allow operation - output empty JSON."""
    print('{}')
    sys.exit(0)

def main():
    # Read hook input from stdin
    try:
        hook_input = json.load(sys.stdin)
    except json.JSONDecodeError:
        allow_and_exit()  # Fail open

    tool_name = hook_input.get("tool_name")
    tool_input = hook_input.get("tool_input", {})

    # Check conditions
    if should_allow():
        allow_and_exit()

    # Block with structured response
    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": "Explanation..."
        }
    }
    json.dump(block_response, sys.stdout)
    sys.exit(0)

if __name__ == "__main__":
    main()
```

### Hook Events

- **PreToolUse**: Runs before tool execution (can block)
- **PostToolUse**: Runs after tool execution (can validate)
- **UserPromptSubmit**: Runs when user submits prompt (can inject context)

### Fail-Open Philosophy

All hooks **fail open** - if the hook encounters an error, it allows the operation to proceed. This prevents hooks from breaking workflows.

---

## Testing Hooks

### Manual Testing

Create test input:
```json
{
  "tool_name": "Bash",
  "tool_input": {
    "command": "git reset --hard"
  }
}
```

Test hook:
```bash
python3 .claude/hooks/php-qa-ci__prevent-destructive-git.py < test-input.json
echo "Exit code: $?"
```

Expected:
- Allow: `{}` and exit code 0
- Block: JSON with `permissionDecision: deny` and exit code 0

### Integration Testing

1. Install hooks in `.claude/hooks/`
2. Trigger via Claude Code (e.g., try to run `git reset --hard`)
3. Verify clear error message appears
4. Try allowed operation, verify it works

---

## Troubleshooting

### Hook Not Running

**Check registration**:
```bash
cat .claude/settings.json | grep -A20 '"hooks"'
```

Should show:
```json
{
  "hooks": {
    "PreToolUse": [{
      "hooks": [
        {
          "type": "command",
          "command": ".claude/hooks/php-qa-ci__prevent-destructive-git.py",
          "timeout": 5
        }
      ]
    }]
  }
}
```

**Check permissions**:
```bash
ls -l .claude/hooks/*.py
```

All should be executable (`-rwxr-xr-x`).

### Hook Blocking Valid Operations

Each hook has escape hatches:

**php-qa-ci__discourage-git-stash.py**:
```bash
git stash  # I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION
```

**php-qa-ci__block-plan-time-estimates.py**:
```markdown
<!-- \*\*Estimated [^:]*\*\*: match is a false positive -->
```

### Hook Errors

Hooks fail open - check Claude Code output for Python errors. Fix Python syntax/imports.

---

## Best Practices

### Recommended Default Set

Deploy these by default in all projects:
1. `php-qa-ci__auto-continue.py` - QoL improvement, no downside
2. `php-qa-ci__prevent-destructive-git.py` - Critical safety, prevents data loss

### Optional Based on Team Standards

Deploy these based on team preferences:
3. `php-qa-ci__discourage-git-stash.py` - If team wants to discourage stash
4. `php-qa-ci__block-plan-time-estimates.py` - If "no time estimates" is a standard
5. `php-qa-ci__validate-claude-readme-content.py` - If instruction-only docs is a standard

### Custom Hooks

To add custom hooks to php-qa-ci:

1. Create hook in `vendor/lts/php-qa-ci/.claude/hooks/`
2. Follow format from existing hooks
3. Test locally first
4. Update this README
5. Commit to php-qa-ci repository

---

## Migration from Old Format

If you have old-format hooks (using `sys.argv`), see the migration guide:

**Old format** (deprecated):
```python
if __name__ == '__main__':
    if len(sys.argv) >= 5:
        main(sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4])
```

**New format** (correct):
```python
if __name__ == "__main__":
    main()  # Reads JSON from stdin
```

For full migration guide, see `/untracked/claude-code-hook-format-migration.md` (in projects that have analyzed hooks).

---

## References

- Claude Code hooks documentation: `.claude/` directory in any Claude Code project
- Hook format examples: All `.py` files in this directory
- Deployment script: `scripts/deploy-skills.bash`
- PHP-QA-CI main docs: `CLAUDE.md` and `docs/`

---

## Summary Table

| Hook | Default? | Purpose | Risk Level |
|------|----------|---------|------------|
| php-qa-ci__auto-continue.py | ✅ YES | Reduce confirmations | None |
| php-qa-ci__prevent-destructive-git.py | ✅ YES | Prevent data loss | None |
| php-qa-ci__discourage-git-stash.py | ⚠️ OPTIONAL | Discourage git stash | Low (has escape hatch) |
| php-qa-ci__block-plan-time-estimates.py | ⚠️ OPTIONAL | No time estimates in plans | Low (configurable paths) |
| php-qa-ci__validate-claude-readme-content.py | ⚠️ OPTIONAL | Instructions only in docs | Medium (may block valid content) |
| php-qa-ci__enforce-markdown-organization.py | ⚠️ OPTIONAL | Enforce doc organization | Medium (opinionated structure) |

**Recommendation**: Always deploy the first two, evaluate others based on team standards.

**Note on php-qa-ci__enforce-markdown-organization.py**: This hook enforces directory conventions (CLAUDE/, docs/, untracked/). If blocked incorrectly, raise with a human to review project structure.
