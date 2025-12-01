#!/usr/bin/env python3
"""
Hook: discourage-git-stash.py

Purpose: BLOCKS git stash usage with escape hatch for confirmed cases

Triggers on: PreToolUse (Bash tool)
Blocks: git stash commands (unless escape hatch phrase present)
Escape Hatch: Include "I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION" in command

Why git stash is dangerous:
- Stashes can be forgotten and lost
- git stash drop/clear permanently destroys work
- Stashes are not part of git graph (can become orphaned)
- Especially problematic in worktree-based workflows
- Generally a bodge to avoid solving real git issues

This hook BLOCKS git stash by default. Use the escape hatch phrase only when truly necessary.
"""

import json
import sys
import re

# Escape hatch phrase - must be exact match in command/comment
ESCAPE_HATCH = "I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION"


def allow_and_exit():
    """Output empty JSON and exit - prevents 'hook error' messages."""
    print('{}')
    sys.exit(0)


def block_and_exit(reason: str):
    """Block the operation with clear reason."""
    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": reason
        }
    }
    json.dump(block_response, sys.stdout)
    sys.exit(0)


def main():
    # Read hook input from stdin
    try:
        hook_input = json.load(sys.stdin)
    except json.JSONDecodeError:
        # If we can't parse input, allow (fail open)
        allow_and_exit()

    # Only check Bash tool calls
    tool_name = hook_input.get("tool_name")
    if tool_name != "Bash":
        allow_and_exit()

    # Extract the command
    tool_input = hook_input.get("tool_input", {})
    command = tool_input.get("command", "")

    # Skip empty commands
    if not command:
        allow_and_exit()

    # Check for escape hatch FIRST - if present, allow unconditionally
    if ESCAPE_HATCH in command:
        # User has explicitly confirmed stash is necessary - allow it
        allow_and_exit()

    # Check for git stash commands
    # We want to BLOCK creating stashes, not viewing/recovering them

    # Safe operations (viewing/recovering) - allow without warning
    safe_stash_patterns = [
        r'git\s+stash\s+list',      # Viewing stash list
        r'git\s+stash\s+show',      # Viewing stash contents
        r'git\s+stash\s+apply',     # Recovering stashed work
        r'git\s+stash\s+branch',    # Creating branch from stash (recovery)
    ]

    for pattern in safe_stash_patterns:
        if re.search(pattern, command, re.IGNORECASE):
            # This is a safe read/recovery operation, allow silently
            allow_and_exit()

    # Dangerous operations (creating stashes) - BLOCK by default
    dangerous_stash_patterns = [
        r'git\s+stash\s*$',                    # git stash
        r'git\s+stash\s+push',                 # git stash push
        r'git\s+stash\s+save',                 # git stash save "message"
        r'git\s+stash\s+-[uap]',               # git stash with flags
        r'git\s+stash\s+--',                   # git stash with long flags
        r'git\s+stash\s+create',               # git stash create
        r'git\s+stash\s+store',                # git stash store
    ]

    for pattern in dangerous_stash_patterns:
        if re.search(pattern, command, re.IGNORECASE):
            # Found a dangerous stash command - BLOCK it
            error_message = f"""
🚫 BLOCKED: git stash is NOT ALLOWED

Command: {command.strip()}

Git stash is DANGEROUS and indicates a workflow problem:

  ❌ Stashes can be FORGOTTEN and LOST
  ❌ git stash drop/clear PERMANENTLY DESTROYS work
  ❌ Stashes are NOT part of git graph (can become orphaned)
  ❌ Especially problematic in worktree-based workflows
  ❌ Usually a bodge to avoid solving the real git issue

BETTER ALTERNATIVES (use these instead):

  ✅ git commit -m "WIP: description of work"
     → Proper version control, visible in git log, can be amended later

  ✅ git checkout -b experiment/feature-name
     → Create a new branch for experimental work, switch back when done

  ✅ git worktree add ../worktree-name
     → Use worktrees for parallel work (proper isolation)

  ✅ git add -p  (then commit)
     → Stage specific changes, leave rest as uncommitted

ESCAPE HATCH (use ONLY if truly necessary):

If you have ABSOLUTELY confirmed that git stash is the only option, include
this exact phrase in your command:

  "I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION"

Example (with comment):
  git stash  # I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION

This will bypass the block. Use this ONLY when you have exhausted all
alternatives and understand the risks.

Ask the user to run git stash manually if they insist it's necessary.
"""
            block_and_exit(error_message)

    # Not a stash command, allow silently
    allow_and_exit()


if __name__ == "__main__":
    main()
