#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

Claude Code Hook: Validate CLAUDE.md and README.md Content

Ensures CLAUDE.md and README.md files contain only useful instructions,
NOT logs, research output, or LLM-generated summaries.

BLOCKED patterns (all case variations):
  - Implementation logs ("Created X", "Modified Y", "Added Z")
  - Research findings, analysis output
  - Test results, QA output
  - Timestamps, dates (except in examples)
  - LLM-style summaries ("## Summary", "## Key Points")
  - Status updates ("✅ Complete", "🟢 Working")
  - File listings, directory trees (unless explaining structure)
  - Generic LLM output patterns

ALLOWED content:
  ✓ Clear, actionable instructions for LLMs/humans
  ✓ Context about directory/module purpose
  ✓ Guidelines, conventions, patterns to follow
  ✓ "How to use this directory" explanations
  ✓ Configuration notes, gotchas, important warnings

Exceptions:
  - Code examples containing blocked patterns
  - Markdown code blocks with ``` delimiters
  - Quoted examples explaining what NOT to do

================================================================================
"""

import json
import re
import sys
from pathlib import Path


# Blocked patterns that indicate logs/research instead of instructions
BLOCKED_PATTERNS = [
    # Implementation logs - require file path patterns or "the" article
    (r'\b(?:created|added|modified|updated|implemented|built|generated)\s+(?:the\s+)?(?:file|directory|class|function|method|component|service|module|package)\s+[a-z0-9_\-./]+', 'Implementation log with artifact type'),
    (r'\b(?:created|added|modified|updated)\s+[a-z0-9_\-./]*\.(?:py|js|ts|php|java|rb|go|rs|cpp|h)\b', 'Implementation log with file extension'),
    (r'\b(?:created|added|modified|updated)\s+src/[a-z0-9_\-./]+', 'Implementation log with src/ path'),
    (r'\b(?:created|added|modified|updated)\s+tests?/[a-z0-9_\-./]+', 'Implementation log with test path'),
    (r'^\s*[-•*]\s*(?:step|phase|task)\s+\d+', 'Numbered procedure format', re.MULTILINE),

    # Status indicators - emojis with status words
    (r'[✅🟢✓]\s*(?:complete|done|working|success|pass|fixed)', 'Status indicator (complete)'),
    (r'[❌🔴✗]\s*(?:fail|block|error|broken)', 'Status indicator (failed)'),
    (r'\b(?:status|progress):\s*\d+%', 'Progress percentage'),

    # Timestamps and dates (unless in code examples)
    (r'\b20\d{2}-\d{2}-\d{2}\b', 'Timestamp (YYYY-MM-DD)'),
    (r'\b(?:created|modified|updated|changed):\s*20\d{2}', 'Date metadata'),

    # Generic LLM summary patterns - more specific to avoid false positives
    (r'^##\s+(?:summary|key points|overview|what i did|summary of changes)\s*$', 'LLM summary heading', re.MULTILINE),
    (r'\b(?:in summary|to summarize|final summary):', 'LLM summary phrase'),

    # Test/QA output patterns
    (r'\d+\s+tests?\s+(?:pass|fail|run|executed)', 'Test results'),
    (r'(?:eslint|typescript|jest).*\d+\s+(?:error|warning)', 'Linter/test output'),
    (r'(?:build|compilation)\s+(?:complete|failed|success)', 'Build output'),

    # File listing patterns
    (r'(?:created|modified|new|deleted)\s+files?:', 'File listing header'),
    (r'(?:modified|created|new|deleted):\s+(?:src/|lib/|\.)', 'File modification list'),

    # Change summary patterns (common in LLM output)
    (r'changes?\s+(?:made|completed|applied):', 'Change summary'),
    (r'(?:what.*?(?:was|were|is|are)\s+)?(?:changed|modified|updated):', 'Change summary pattern'),

    # "All done" / completion patterns
    (r'\b(?:all done|that\'?s all|complete!|finished!)\b', 'Completion indicator'),
    (r'\b(?:that should|that will|this should)\s+(?:fix|resolve|address)\b', 'LLM assurance phrase'),
]


def is_in_code_block(content: str, match_start: int) -> bool:
    """Check if a match is inside a markdown code block (between ``` markers)."""
    # Count ``` markers before the match position
    before = content[:match_start]
    fence_count = before.count('```')
    # If odd number of fences, we're inside a code block
    return fence_count % 2 == 1


def find_blocked_content(content: str, file_path: str) -> list:
    """Check content for blocked patterns.

    Returns list of dicts with keys: line_num, pattern_desc, matched_text
    """
    issues = []

    for pattern_tuple in BLOCKED_PATTERNS:
        pattern_regex = pattern_tuple[0]
        pattern_desc = pattern_tuple[1]
        re_flags = pattern_tuple[2] if len(pattern_tuple) > 2 else 0

        # Find all matches in content
        for match in re.finditer(pattern_regex, content, re_flags | re.IGNORECASE):
            # Skip if inside a code block
            if is_in_code_block(content, match.start()):
                continue

            # Find line number
            line_num = content[:match.start()].count('\n') + 1

            # Get the matched text
            matched_text = match.group(0)

            # Avoid duplicate issues on same line with same pattern
            if not any(
                issue['line'] == line_num and issue['pattern_desc'] == pattern_desc
                for issue in issues
            ):
                issues.append({
                    'line': line_num,
                    'pattern_desc': pattern_desc,
                    'matched_text': matched_text.replace('\n', ' '),
                })

    return issues


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

    # Only check Write and Edit tools
    tool_name = hook_input.get("tool_name")
    if tool_name not in ["Write", "Edit"]:
        allow_and_exit()

    # Only check CLAUDE.md and README.md files
    file_path = hook_input.get("tool_input", {}).get("file_path", "")
    if not file_path:
        allow_and_exit()

    # Check if this is a CLAUDE.md or README.md file
    file_name = Path(file_path).name
    if file_name not in ["CLAUDE.md", "README.md"]:
        allow_and_exit()

    # Get the content to check
    tool_input = hook_input.get("tool_input", {})

    # For Write operations: check the content being written
    # For Edit operations: read the file and apply the edit
    if tool_name == "Write":
        content = tool_input.get("content", "")
    elif tool_name == "Edit":
        # Read the current file if it exists
        file_path_obj = Path(file_path)
        if file_path_obj.exists():
            try:
                current_content = file_path_obj.read_text()
            except Exception:
                # If we can't read, allow (fail open)
                allow_and_exit()

            # Apply the edit to get what the file would look like
            old_string = tool_input.get("old_string", "")
            new_string = tool_input.get("new_string", "")

            if old_string in current_content:
                # Simulate the edit
                content = current_content.replace(old_string, new_string, 1)
            else:
                # Can't simulate edit, check the new_string at least
                content = new_string
        else:
            # File doesn't exist yet, check the new_string
            content = tool_input.get("new_string", "")
    else:
        allow_and_exit()

    if not content:
        allow_and_exit()

    # Check for blocked content
    issues = find_blocked_content(content, file_path)

    if not issues:
        allow_and_exit()

    # Build error message with all detected issues
    error_lines = [
        "",
        f"⚠️  {file_name} CONTENT VALIDATION",
        "",
        f"File: {file_path}",
        "",
        "This file appears to contain logs/research rather than instructions.",
        "",
        "Detected issues:",
    ]

    # Show each issue with context
    for issue in issues:
        matched_text = issue['matched_text']
        if len(matched_text) > 77:
            matched_text = matched_text[:77] + "..."
        error_lines.append(
            f"  • Line {issue['line']}: {issue['pattern_desc']}")
        error_lines.append(f"    \"{matched_text}\"")

    error_lines.extend([
        "",
        f"{file_name} files should contain:",
        "  ✓ Clear instructions for LLMs/humans",
        "  ✓ Context about directory purpose",
        "  ✓ Guidelines and conventions",
        "  ✓ Configuration notes and gotchas",
        "",
        f"{file_name} files should NOT contain:",
        "  ✗ Implementation logs",
        "  ✗ Research findings",
        "  ✗ Test results or QA output",
        "  ✗ Status updates or emojis",
        "  ✗ Timestamps or dates",
        "  ✗ LLM-style summaries",
        "",
        "───────────────────────────────────────────────────────────",
        "",
        "DO NOT write content that documents what you did or how you did it.",
        "Instead, write instructions for how OTHERS should use this directory/file.",
        "",
    ])

    # Use proper JSON response format for PreToolUse
    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": "\n".join(error_lines)
        }
    }

    json.dump(block_response, sys.stdout)
    sys.exit(0)


def self_test():
    """Run comprehensive self-tests."""
    import io

    print("Running self-tests for php-qa-ci__validate-claude-readme-content...")
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
                print(f"   Reason: {hook_output.get('permissionDecisionReason', 'N/A')[:120]}")
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

    # Test 2: Non-Write/Edit tool (should allow)
    run_test(
        "Non-Write/Edit tool (Bash)",
        {"tool_name": "Bash", "tool_input": {"command": "ls"}},
        expect_allow=True
    )

    # Test 3: Non-CLAUDE.md/README.md file (should allow)
    run_test(
        "Non-CLAUDE.md file (plan.md)",
        {"tool_name": "Write", "tool_input": {"file_path": "CLAUDE/plan.md", "content": "Created file X"}},
        expect_allow=True
    )

    # Test 4: CLAUDE.md with valid instructions (should allow)
    run_test(
        "CLAUDE.md with instructions (allowed)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "# Instructions\n\nThis directory contains tools for testing.\n\nGuidelines:\n- Use PHPStan level max\n- Follow PSR-12"
        }},
        expect_allow=True
    )

    # Test 5: README.md with valid content (should allow)
    run_test(
        "README.md with instructions (allowed)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "# My Project\n\nHow to use:\n1. Install dependencies\n2. Run tests\n\nConfiguration notes here."
        }},
        expect_allow=True
    )

    # Test 6: BLOCK - Implementation log "Created the file"
    run_test(
        "BLOCK: Implementation log (created the file)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "Created the file src/Controller.php to handle requests"
        }},
        expect_allow=False
    )

    # Test 7: BLOCK - Implementation log "Modified src/"
    run_test(
        "BLOCK: Implementation log (modified src/)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "Modified src/Service.php to add validation"
        }},
        expect_allow=False
    )

    # Test 8: BLOCK - Implementation log "added function"
    run_test(
        "BLOCK: Implementation log (added function)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "Added function calculateTotal() to process payments"
        }},
        expect_allow=False
    )

    # Test 9: BLOCK - Implementation log with file extension
    run_test(
        "BLOCK: Implementation log (created .php)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "Created Helper.php with utility methods"
        }},
        expect_allow=False
    )

    # Test 10: BLOCK - Status indicator "✅ Complete"
    run_test(
        "BLOCK: Status indicator (✅ Complete)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "✅ Complete - All tests passing"
        }},
        expect_allow=False
    )

    # Test 11: BLOCK - Status indicator "🟢 Working"
    run_test(
        "BLOCK: Status indicator (🟢 Working)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "🟢 Working on new feature"
        }},
        expect_allow=False
    )

    # Test 12: BLOCK - Timestamp YYYY-MM-DD
    run_test(
        "BLOCK: Timestamp (2024-12-15)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "Updated: 2024-12-15\n\nImplementation notes"
        }},
        expect_allow=False
    )

    # Test 13: BLOCK - LLM summary heading
    run_test(
        "BLOCK: LLM summary heading",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "## Summary\n\nI have completed the implementation of feature X"
        }},
        expect_allow=False
    )

    # Test 14: BLOCK - Test results
    run_test(
        "BLOCK: Test results",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "15 tests passed, 2 failed"
        }},
        expect_allow=False
    )

    # Test 15: BLOCK - Change summary
    run_test(
        "BLOCK: Change summary",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "Changes made:\n- Updated controller\n- Fixed bug"
        }},
        expect_allow=False
    )

    # Test 16: Code block exemption - should allow "created" in code block
    run_test(
        "Code block exemption (allowed)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "Example:\n```bash\n# Created the file manually\ntouch file.php\n```"
        }},
        expect_allow=True
    )

    # Test 17: Case insensitive matching
    run_test(
        "BLOCK: Case insensitive (CREATED)",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "CREATED THE FILE src/test.php"
        }},
        expect_allow=False
    )

    # Test 18: Edit operation
    run_test(
        "Edit operation adding log",
        {"tool_name": "Edit", "tool_input": {
            "file_path": "CLAUDE.md",
            "old_string": "Instructions",
            "new_string": "Instructions\n\nCreated the file Helper.php"
        }},
        expect_allow=False
    )

    # Test 19: Multiple issues in same file
    run_test(
        "BLOCK: Multiple issues",
        {"tool_name": "Write", "tool_input": {
            "file_path": "README.md",
            "content": "Created file.php\n\n✅ Complete\n\n2024-12-15"
        }},
        expect_allow=False
    )

    # Test 20: Edge case - numbered procedure (Step 1)
    run_test(
        "BLOCK: Numbered procedure",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE.md",
            "content": "- step 1: Create database\n- step 2: Run migrations"
        }},
        expect_allow=False
    )

    print("=" * 60)
    print(f"Results: {passed} passed, {failed} failed")
    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        self_test()
    else:
        main()
