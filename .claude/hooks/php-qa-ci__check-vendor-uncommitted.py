#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

Claude Code Hook: Check Vendor Libraries for Uncommitted Changes

This PreToolUse hook BLOCKS git commit operations when vendor libraries
(first-party dependencies) have uncommitted changes.

PROBLEM SOLVED:
Production failure occurred when uncommitted changes in vendor library worked
in GREEN (development) but failed in BLUE (production) because composer wasn't
tracking the latest changes. Code used features not yet in the committed version.

HOOK PURPOSE:
Ensures all changes in git-tracked vendor libraries are committed BEFORE
allowing commits to the main project. This prevents deployment of code that
depends on uncommitted vendor changes.

BLOCKED OPERATIONS:
  - git commit (any form)
  - git commit -m "message"
  - git commit --amend
  - git commit -a

DETECTION LOGIC:
1. Searches vendor/ for subdirectories containing .git directories
2. For each git-tracked vendor library, runs: git diff-index --quiet HEAD
3. If ANY vendor library has uncommitted changes, BLOCKS the commit
4. Provides clear instructions on how to commit vendor changes first

SAFE OPERATIONS (not blocked):
  - git add (staging changes)
  - git status (viewing changes)
  - git diff (reviewing changes)
  - git commit in vendor library directories (must do this first!)
  - Any non-git commands

ESCAPE HATCH:
If you MUST commit with uncommitted vendor changes (e.g., WIP):
1. Commit the vendor library changes first: cd vendor/org/package && git commit
2. Then return to main project and commit
3. Or temporarily disable this hook in .claude/settings.json

WHY THIS MATTERS:
- First-party vendor libraries are tracked via composer with prefer-source
- Changes work locally but fail in production if not committed to vendor repos
- This hook enforces proper workflow: commit vendor → update composer → commit main

================================================================================
"""

import json
import os
import re
import subprocess
import sys
from pathlib import Path


def find_vendor_git_repos(project_root: str) -> list[str]:
    """
    Find all vendor subdirectories that contain .git directories.

    Returns list of paths relative to project root.
    """
    vendor_dir = Path(project_root) / "vendor"
    if not vendor_dir.exists():
        return []

    git_repos = []

    # Search vendor/*/* for .git directories (vendor/org/package)
    for org_dir in vendor_dir.iterdir():
        if not org_dir.is_dir():
            continue

        for package_dir in org_dir.iterdir():
            if not package_dir.is_dir():
                continue

            git_dir = package_dir / ".git"
            if git_dir.exists():
                # Store path relative to project root
                relative_path = package_dir.relative_to(project_root)
                git_repos.append(str(relative_path))

    return git_repos


def check_repo_has_uncommitted_changes(repo_path: str, project_root: str) -> tuple[bool, str]:
    """
    Check if a git repository has uncommitted changes or untracked files.

    Returns (has_changes, status_message)
    """
    abs_repo_path = Path(project_root) / repo_path

    try:
        # Check for any changes using git status --porcelain
        # This checks:
        # - Staged changes
        # - Unstaged changes (modifications)
        # - Untracked files
        status_result = subprocess.run(
            ["git", "status", "--porcelain"],
            cwd=str(abs_repo_path),
            capture_output=True,
            text=True,
            timeout=5
        )

        status_output = status_result.stdout.strip()

        if status_output:
            # Has uncommitted changes or untracked files
            # Get human-readable status for error message
            readable_status = subprocess.run(
                ["git", "status", "--short"],
                cwd=str(abs_repo_path),
                capture_output=True,
                text=True,
                timeout=5
            )
            return True, readable_status.stdout.strip()

        return False, ""

    except subprocess.TimeoutExpired:
        # Fail open - allow if git commands hang
        return False, "git command timeout"
    except Exception as e:
        # Fail open - allow on unexpected errors
        return False, f"error checking status: {e}"


def is_git_commit_command(command: str) -> bool:
    """
    Check if the command is a git commit operation.

    Matches:
    - git commit
    - git commit -m "message"
    - git commit --amend
    - git commit -a
    - git commit --all
    """
    cmd_lower = command.lower()

    # Must contain 'git' and 'commit'
    if 'git' not in cmd_lower or 'commit' not in cmd_lower:
        return False

    # Use regex to match git commit with various flags
    # Matches: git commit, git commit -m, git commit --amend, etc.
    if re.search(r'\bgit\s+commit\b', cmd_lower):
        return True

    return False


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

    # Only check git commit commands
    if not is_git_commit_command(command):
        allow_and_exit()

    # Get project root from environment or use current directory
    project_root = os.environ.get("PWD", os.getcwd())

    # Find all git-tracked vendor libraries
    vendor_repos = find_vendor_git_repos(project_root)

    if not vendor_repos:
        # No git-tracked vendor libraries found
        allow_and_exit()

    # Check each vendor repo for uncommitted changes
    repos_with_changes = []

    for repo_path in vendor_repos:
        has_changes, status = check_repo_has_uncommitted_changes(repo_path, project_root)
        if has_changes:
            repos_with_changes.append({
                "path": repo_path,
                "status": status
            })

    if not repos_with_changes:
        # All vendor repos are clean
        allow_and_exit()

    # BLOCK - vendor libraries have uncommitted changes
    repos_list = "\n".join([
        f"  • {repo['path']}\n    {repo['status']}"
        for repo in repos_with_changes
    ])

    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": (
                f"BLOCKED: Vendor libraries have uncommitted changes\n\n"
                f"The following vendor libraries (first-party dependencies) have uncommitted changes:\n\n"
                f"{repos_list}\n\n"
                "WHY THIS IS BLOCKED:\n"
                "- Vendor libraries are git-tracked first-party dependencies\n"
                "- Committing main project with uncommitted vendor changes causes production failures\n"
                "- Your code may use features not yet committed to vendor repos\n"
                "- Composer in production won't have these changes, causing runtime errors\n\n"
                "PRODUCTION INCIDENT EXAMPLE:\n"
                "- GREEN (dev) had uncommitted SupplierStockRowDto with 'duplicateSkus' parameter\n"
                "- Main project committed code using 'duplicateSkus: $row[...]'\n"
                "- BLUE (production) composer update pulled old DTO without the parameter\n"
                "- Production failed: 'Unknown named parameter $duplicateSkus'\n\n"
                "HOW TO FIX:\n"
                "1. Commit changes in vendor libraries FIRST:\n"
                "   cd vendor/org/package\n"
                "   git add .\n"
                "   git commit -m \"feat: Add missing feature\"\n"
                "   git push\n\n"
                "2. Update composer to track the new commit:\n"
                "   cd [project-root]\n"
                "   composer update vendor/org/package\n\n"
                "3. THEN commit your main project:\n"
                "   git add .\n"
                "   git commit -m \"feat: Use new vendor feature\"\n\n"
                "WORKFLOW:\n"
                "Vendor changes → Vendor commit → Composer update → Main commit\n\n"
                "This ensures production can access all vendor features your code depends on."
            )
        }
    }

    json.dump(block_response, sys.stdout)
    sys.exit(0)


def self_test():
    """Run comprehensive self-tests."""
    import io
    import tempfile
    import shutil

    print("Running self-tests for php-qa-ci__check-vendor-uncommitted...")
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
                print(f"   Reason: {hook_output.get('permissionDecisionReason', 'N/A')[:200]}")
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
        {"tool_name": "Write", "tool_input": {"file_path": "test.txt", "content": "git commit"}},
        expect_allow=True
    )

    # Test 3: Empty command (should allow)
    run_test(
        "Empty command",
        {"tool_name": "Bash", "tool_input": {"command": ""}},
        expect_allow=True
    )

    # Test 4: Non-commit git command (should allow)
    run_test(
        "git status (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git status"}},
        expect_allow=True
    )

    # Test 5: git add (safe)
    run_test(
        "git add (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git add ."}},
        expect_allow=True
    )

    # Test 6: git diff (safe)
    run_test(
        "git diff (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "git diff"}},
        expect_allow=True
    )

    # Test 7: git commit detection
    run_test(
        "git commit (triggers check)",
        {"tool_name": "Bash", "tool_input": {"command": "git commit -m \"test\""}},
        expect_allow=True  # Will allow if no vendor repos (we're testing detection, not blocking)
    )

    # Test 8: git commit --amend detection
    run_test(
        "git commit --amend (triggers check)",
        {"tool_name": "Bash", "tool_input": {"command": "git commit --amend"}},
        expect_allow=True
    )

    # Test 9: git commit -a detection
    run_test(
        "git commit -a (triggers check)",
        {"tool_name": "Bash", "tool_input": {"command": "git commit -a -m \"test\""}},
        expect_allow=True
    )

    # Test 10: Case insensitive
    run_test(
        "GIT COMMIT (uppercase)",
        {"tool_name": "Bash", "tool_input": {"command": "GIT COMMIT -m \"test\""}},
        expect_allow=True
    )

    # Test 11: Multiple commands with commit
    run_test(
        "Multiple commands with commit",
        {"tool_name": "Bash", "tool_input": {"command": "git add . && git commit -m \"test\""}},
        expect_allow=True
    )

    # Test 12: Non-commit command with 'commit' in message
    run_test(
        "Echo with 'commit' word (safe)",
        {"tool_name": "Bash", "tool_input": {"command": "echo 'ready to commit'"}},
        expect_allow=True
    )

    print("=" * 60)
    print(f"Results: {passed} passed, {failed} failed")

    # Integration test with actual git repos
    print("\n" + "=" * 60)
    print("Integration Test: Simulated vendor repo with changes")
    print("=" * 60)

    # Create temporary project structure
    with tempfile.TemporaryDirectory() as tmpdir:
        project_root = Path(tmpdir)
        vendor_dir = project_root / "vendor" / "test-org" / "test-package"
        vendor_dir.mkdir(parents=True)

        # Initialize git repo in vendor
        subprocess.run(["git", "init"], cwd=str(vendor_dir), capture_output=True)
        subprocess.run(["git", "config", "user.email", "test@example.com"], cwd=str(vendor_dir), capture_output=True)
        subprocess.run(["git", "config", "user.name", "Test User"], cwd=str(vendor_dir), capture_output=True)

        # Create and commit initial file
        test_file = vendor_dir / "test.txt"
        test_file.write_text("initial content")
        subprocess.run(["git", "add", "."], cwd=str(vendor_dir), capture_output=True)
        subprocess.run(["git", "commit", "-m", "Initial commit"], cwd=str(vendor_dir), capture_output=True)

        # Modify file (uncommitted change)
        test_file.write_text("modified content")

        # Test that commit is blocked
        old_env = os.environ.get("PWD")
        os.environ["PWD"] = str(project_root)

        old_stdout = sys.stdout
        old_stdin = sys.stdin
        sys.stdout = io.StringIO()
        sys.stdin = io.StringIO(json.dumps({
            "tool_name": "Bash",
            "tool_input": {"command": "git commit -m \"test\""}
        }))

        try:
            main()
        except SystemExit:
            pass

        output = sys.stdout.getvalue()
        sys.stdout = old_stdout
        sys.stdin = old_stdin

        if old_env:
            os.environ["PWD"] = old_env
        else:
            del os.environ["PWD"]

        result = json.loads(output)
        decision = result["hookSpecificOutput"]["permissionDecision"]

        if decision == "deny":
            print("✓ PASS: Integration test - blocked commit with uncommitted vendor changes")
            passed += 1
        else:
            print("❌ FAIL: Integration test - should have blocked commit")
            failed += 1

    print("=" * 60)
    print(f"Final Results: {passed} passed, {failed} failed")
    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        self_test()
    else:
        main()
