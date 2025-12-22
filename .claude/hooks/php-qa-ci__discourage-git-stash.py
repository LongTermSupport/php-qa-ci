#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

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

================================================================================
"""

import json
import sys
import re

# Escape hatch phrase - must be exact match in command/comment
ESCAPE_HATCH = "I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION"


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


def self_test():
    """Run comprehensive self-tests."""
    import io

    print("Running self-tests for php-qa-ci__discourage-git-stash...")
    print("=" * 60)

    passed = 0
    failed = 0

    def run_test(name, hook_input, expect_allow):
        nonlocal passed, failed
        old_stdout = sys.stdout
        old_stdin = sys.stdin
        sys.stdout = io.StringIO()
        sys.stdin = io.StringIO(json.dumps(hook_input))

        try:
            main()
        except SystemExit:
            pass

        output = sys.stdout.getvalue()
        sys.stdout = old_stdout
        sys.stdin = old_stdin

        # Validate JSON
        try:
            result = json.loads(output)
        except json.JSONDecodeError:
            print(f"❌ FAIL: {name}")
            print(f"   Invalid JSON output: {output[:100]}")
            failed += 1
            return

        # Validate structure
        if "hookSpecificOutput" not in result:
            print(f"❌ FAIL: {name}")
            print(f"   Missing hookSpecificOutput in response")
            failed += 1
            return

        hook_output = result["hookSpecificOutput"]
        if "permissionDecision" not in hook_output:
            print(f"❌ FAIL: {name}")
            print(f"   Missing permissionDecision in response")
            failed += 1
            return

        decision = hook_output["permissionDecision"]
        expected_decision = "allow" if expect_allow else "deny"

        if decision == expected_decision:
            print(f"✓ PASS: {name}")
            passed += 1
        else:
            print(f"❌ FAIL: {name}")
            print(f"   Expected {expected_decision}, got {decision}")
            if not expect_allow:
                print(f"   Reason: {hook_output.get('permissionDecisionReason', 'N/A')[:100]}")
            failed += 1

    # Test 1: Invalid JSON (fail open)
    old_stdin = sys.stdin
    sys.stdin = io.StringIO("not json")
    old_stdout = sys.stdout
    sys.stdout = io.StringIO()
    try:
        main()
    except SystemExit:
        pass
    output = sys.stdout.getvalue()
    sys.stdout = old_stdout
    sys.stdin = old_stdin

    try:
        result = json.loads(output)
        if result["hookSpecificOutput"]["permissionDecision"] == "allow":
            print(f"✓ PASS: Invalid JSON (fail open)")
            passed += 1
        else:
            print(f"❌ FAIL: Invalid JSON (fail open)")
            failed += 1
    except:
        print(f"❌ FAIL: Invalid JSON (fail open)")
        failed += 1

    # Test 2: Non-Bash tool (should allow)
    run_test(
        "Non-Bash tool (Write)",
        {"tool_name": "Write", "tool_input": {"file_path": "test.txt", "content": "git stash"}},
        expect_allow=True
    )

    # Test 3: Empty command (should allow)
    run_test(
        "Empty command",
        {"tool_name": "Bash", "tool_input": {"command": ""}},
        expect_allow=True
    )

    # Test 4: Non-git command (should allow)
    run_test(
        "Non-git command",
        {"tool_name": "Bash", "tool_input": {"command": "ls -la"}},
        expect_allow=True
    )

    # Test 5: Safe stash operation - list (should allow)
    run_test(
        "git stash list (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash list"}},
        expect_allow=True
    )

    # Test 6: Safe stash operation - show (should allow)
    run_test(
        "git stash show (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash show stash@{0}"}},
        expect_allow=True
    )

    # Test 7: Safe stash operation - apply (should allow)
    run_test(
        "git stash apply (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash apply"}},
        expect_allow=True
    )

    # Test 8: Safe stash operation - branch (should allow)
    run_test(
        "git stash branch (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash branch recovery-branch"}},
        expect_allow=True
    )

    # Test 9: BLOCK - git stash (no subcommand)
    run_test(
        "BLOCK: git stash (dangerous)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash"}},
        expect_allow=False
    )

    # Test 10: BLOCK - git stash push
    run_test(
        "BLOCK: git stash push",
        {"tool_name": "Bash", "tool_input": {"command": "git stash push -m 'WIP'"}},
        expect_allow=False
    )

    # Test 11: BLOCK - git stash save
    run_test(
        "BLOCK: git stash save",
        {"tool_name": "Bash", "tool_input": {"command": "git stash save 'work in progress'"}},
        expect_allow=False
    )

    # Test 12: BLOCK - git stash with flags
    run_test(
        "BLOCK: git stash -u (with flags)",
        {"tool_name": "Bash", "tool_input": {"command": "git stash -u"}},
        expect_allow=False
    )

    # Test 13: BLOCK - git stash create
    run_test(
        "BLOCK: git stash create",
        {"tool_name": "Bash", "tool_input": {"command": "git stash create"}},
        expect_allow=False
    )

    # Test 14: BLOCK - git stash store
    run_test(
        "BLOCK: git stash store",
        {"tool_name": "Bash", "tool_input": {"command": "git stash store abc123"}},
        expect_allow=False
    )

    # Test 15: Escape hatch - allow with magic phrase
    run_test(
        "Escape hatch: git stash with confirmation",
        {"tool_name": "Bash", "tool_input": {
            "command": "git stash  # I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION"
        }},
        expect_allow=True
    )

    # Test 16: Case insensitive matching
    run_test(
        "Case insensitive: GIT STASH",
        {"tool_name": "Bash", "tool_input": {"command": "GIT STASH"}},
        expect_allow=False
    )

    # Test 17: Escape hatch in middle of command
    run_test(
        "Escape hatch in middle",
        {"tool_name": "Bash", "tool_input": {
            "command": "# I HAVE ABSOLUTELY CONFIRMED THAT STASH IS THE ONLY OPTION\ngit stash push"
        }},
        expect_allow=True
    )

    # Test 18: Multiple commands (with stash) - note: This should block since escape hatch isn't present
    # However the current regex doesn't block if && is present, so this might not work as expected
    # Updating test to expect current behavior
    run_test(
        "Multiple commands with stash",
        {"tool_name": "Bash", "tool_input": {"command": "git stash push && git pull"}},
        expect_allow=False  # Will block on "git stash push"
    )

    print("=" * 60)
    print(f"Results: {passed} passed, {failed} failed")
    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        self_test()
    else:
        main()
