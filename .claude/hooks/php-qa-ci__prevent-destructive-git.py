#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

Claude Code Hook: Prevent Destructive Git Commands

This PreToolUse hook TOTALLY BLOCKS git commands that can permanently destroy
uncommitted changes without any possibility of recovery.

BLOCKED commands (non-recoverable data loss):
  - git reset --hard
  - git checkout <path> (overwrites file with repo version)
  - git checkout . (overwrites all files)
  - git checkout -- <path>
  - git restore --staged --worktree (full restore)
  - git clean -f/-fd/-fx (deletes untracked files)
  - git stash drop/clear (destroys stashed changes)

If the LLM needs to run these commands, it must ask the human user to do it.

This hook does NOT block:
  - git reset (without --hard) - safe, only moves HEAD
  - git reset --soft - safe, keeps changes staged
  - git checkout <branch> - safe, switches branches
  - git restore --staged - safe, only unstages
  - git stash (without drop/clear) - safe, preserves changes

================================================================================
"""

import json
import re
import sys


def is_destructive_git_command(command: str) -> tuple[bool, str]:
    """
    Check if the command contains a destructive git operation.

    Returns (is_destructive, reason)
    """
    # Normalize command for easier matching
    cmd_lower = command.lower()

    # git reset --hard - destroys all uncommitted changes
    if re.search(r'\bgit\s+reset\s+.*--hard\b', cmd_lower):
        return True, "git reset --hard destroys all uncommitted changes permanently"

    if re.search(r'\bgit\s+reset\s+--hard\b', cmd_lower):
        return True, "git reset --hard destroys all uncommitted changes permanently"

    # git checkout with path - overwrites file(s) with repository version
    # Matches: git checkout path/to/file, git checkout ., git checkout -- path
    # But NOT: git checkout branch-name (no path-like characters except simple names)

    # git checkout . - destroys all uncommitted changes in working directory
    if re.search(r'\bgit\s+checkout\s+\.\s*(?:$|;|&&|\|)', command):
        return True, "git checkout . destroys all uncommitted changes in working directory"

    # git checkout -- <path> - explicitly overwrites specific file(s)
    if re.search(r'\bgit\s+checkout\s+--\s+', cmd_lower):
        return True, "git checkout -- <path> overwrites uncommitted changes in specified files"

    # git checkout <path> where path contains / or is a file pattern
    # This catches: git checkout src/file.php, git checkout *.txt
    # But allows: git checkout main, git checkout feature-branch
    checkout_match = re.search(r'\bgit\s+checkout\s+([^\s;|&]+)', command)
    if checkout_match:
        target = checkout_match.group(1)
        # If target contains path separators, wildcards, or file extensions, it's a file path
        if '/' in target or '*' in target or re.search(r'\.\w+$', target):
            # Check it's not a branch name with slashes (feature/something)
            # Branch names typically don't have file extensions
            if re.search(r'\.\w{1,5}$', target):  # Has file extension
                return True, f"git checkout {target} overwrites uncommitted changes in that file"

    # git restore with --worktree (and optionally --staged) - overwrites working directory
    if re.search(r'\bgit\s+restore\s+.*--worktree\b', cmd_lower):
        return True, "git restore --worktree overwrites uncommitted changes in working directory"

    # git restore . or git restore <path> without --staged - overwrites working directory
    restore_match = re.search(r'\bgit\s+restore\s+([^\s;|&-][^\s;|&]*)', command)
    if restore_match:
        target = restore_match.group(1)
        # Check if it has --staged flag (which is safe, only unstages)
        if '--staged' not in command or '--worktree' in command:
            if target == '.' or '/' in target or re.search(r'\.\w+$', target):
                return True, f"git restore {target} overwrites uncommitted changes"

    # git clean -f (force delete untracked files)
    if re.search(r'\bgit\s+clean\s+.*-[a-z]*f', cmd_lower):
        return True, "git clean -f permanently deletes untracked files"

    # git stash drop - destroys stashed changes
    if re.search(r'\bgit\s+stash\s+drop\b', cmd_lower):
        return True, "git stash drop permanently destroys stashed changes"

    # git stash clear - destroys ALL stashed changes
    if re.search(r'\bgit\s+stash\s+clear\b', cmd_lower):
        return True, "git stash clear permanently destroys all stashed changes"

    # git checkout HEAD -- <path> - overwrites with HEAD version
    if re.search(r'\bgit\s+checkout\s+HEAD\s+--', cmd_lower):
        return True, "git checkout HEAD -- <path> overwrites uncommitted changes"

    # git checkout HEAD^ -- or any ref with --
    if re.search(r'\bgit\s+checkout\s+\S+\s+--\s+', cmd_lower):
        return True, "git checkout <ref> -- <path> overwrites uncommitted changes"

    return False, ""


def allow_and_exit():
    """Allow the tool to execute with proper JSON format."""
    result = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "allow",
            "permissionDecisionReason": "Hook allows this operation"
        }
    }
    print(json.dumps(result))
    sys.exit(0)


def main():
    # Read hook input from stdin
    try:
        hook_input = json.load(sys.stdin)
    except json.JSONDecodeError:
        # If we can't parse input, allow (fail open)
        allow_and_exit()

    # Only check Bash tool calls
    if hook_input.get("tool_name") != "Bash":
        allow_and_exit()

    # Get the command being executed
    tool_input = hook_input.get("tool_input", {})
    command = tool_input.get("command", "")

    # Check if command contains git
    if 'git' not in command.lower():
        allow_and_exit()

    # Check if it's a destructive git command
    is_destructive, reason = is_destructive_git_command(command)

    if not is_destructive:
        allow_and_exit()

    # BLOCK the destructive command
    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": (
                f"BLOCKED: Destructive git command detected\n\n"
                f"Reason: {reason}\n\n"
                f"Command: {command}\n\n"
                "This command PERMANENTLY DESTROYS uncommitted changes with NO recovery possible.\n\n"
                "If this operation is truly necessary, you must ask the human user to run it manually.\n\n"
                "SAFE alternatives:\n"
                "  - git stash        (save changes, can recover later)\n"
                "  - git diff         (review changes first)\n"
                "  - git status       (see what would be affected)\n"
                "  - git commit       (save changes permanently first)\n\n"
                "The LLM is NOT ALLOWED to run destructive git commands. Ask the user to do it."
            )
        }
    }

    json.dump(block_response, sys.stdout)
    sys.exit(0)


if __name__ == "__main__":
    main()
