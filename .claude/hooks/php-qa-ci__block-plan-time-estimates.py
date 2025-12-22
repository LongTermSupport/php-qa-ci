#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

Claude Code Hook: Block Time Estimates in Plan Documents

Prevents time estimates and completion dates from being written to plan markdown files.
Time estimates are 100% useless context bloat that should never appear in plan documents.

BLOCKED patterns (all case variations):
  - "Estimated Effort: X hours/minutes/days/weeks"
  - "Time estimated: ..."
  - "Estimated time: ..."
  - "Total Estimated Time: ..."
  - "**Estimated Effort**: ..."
  - "Target Completion: YYYY-MM-DD"
  - "Completion: YYYY-MM-DD"
  - Timeline sections with dates/durations like "Phase 1: X hours"
  - "Timeline" sections containing any time duration

Plans should focus on:
  ✓ What needs to be done
  ✓ Why it's needed
  ✓ How to implement it
  ✗ NOT when it will be done
  ✗ NOT how long it takes

Configuration:
  Set PLAN_DIRECTORY_PATTERNS environment variable to customize paths (comma-separated):
  export PLAN_DIRECTORY_PATTERNS="CLAUDE/Plan/,CLAUDE/plan/,.claude/plans/,docs/plans/"

  Default patterns checked:
  - CLAUDE/Plan/
  - CLAUDE/plan/
  - .claude/plans/
  - docs/plans/

Escape hatch:
  If a pattern match is a false positive (genuinely not a time estimate), add this comment:
  <!-- pattern-match is a false positive for time estimate blocking hook -->

================================================================================
"""

import json
import os
import re
import sys
from pathlib import Path


# Default plan directory patterns - can be overridden via environment variable
DEFAULT_PLAN_PATTERNS = [
    "CLAUDE/Plan/",
    "CLAUDE/plan/",
    ".claude/plans/",
    "docs/plans/",
]


# Regex patterns that detect time estimates
# Using DOTALL flag for multiline matching where needed
TIME_ESTIMATE_PATTERNS = [
    # Estimated Effort patterns
    (r'\*\*Estimated\s+Effort\*\*:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Estimated Effort with duration'),
    (r'Estimated\s+Effort:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Estimated Effort with duration'),

    # Time estimated/Estimated time patterns
    (r'(?:Time\s+)?[Ee]stimated\s+(?:time)?:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Time estimate field'),

    # Total Estimated Time
    (r'\*\*Total\s+Estimated\s+Time\*\*:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Total Estimated Time'),
    (r'Total\s+Estimated\s+Time:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Total Estimated Time'),

    # Target Completion with dates
    (r'\*\*Target\s+Completion\*\*:\s*\d{4}-\d{2}-\d{2}', 'Target Completion date'),
    (r'Target\s+Completion:\s*\d{4}-\d{2}-\d{2}', 'Target Completion date'),

    # Completion date patterns
    (r'\*\*Completion\*\*:\s*\d{4}-\d{2}-\d{2}', 'Completion date'),
    (r'Completion:\s*\d{4}-\d{2}-\d{2}', 'Completion date'),

    # Phase with time estimates (e.g., "Phase 1: 2 hours")
    (r'[-•*]\s*\*\*Phase\s+\d+:\s*[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Phase with time estimate'),
    (r'###\s+Phase\s+\d+:[^\n]*(?:hours?|minutes?|days?|weeks?)', 'Phase heading with time estimate'),

    # Stand-alone duration mentions in specific contexts
    # E.g., "- **Phase 1**: 1.5 hours" or "- 2 hours" at start of line
    (r'[-•*]\s*\*\*[^*]*\*\*:\s*[0-9.]+\s*(?:hours?|minutes?|days?|weeks?)', 'Task/Phase with duration'),

    # Duration in list items (e.g., "- 1 hour", "- 2.5 days")
    (r'[-•*]\s+[0-9.]+\s*(?:hours?|minutes?|days?|weeks?)', 'List item with duration'),
]


def get_plan_patterns():
    """Get plan directory patterns from environment or use defaults."""
    patterns_env = os.environ.get('PLAN_DIRECTORY_PATTERNS', '')
    if patterns_env:
        # Parse comma-separated patterns
        return [p.strip() for p in patterns_env.split(',') if p.strip()]
    return DEFAULT_PLAN_PATTERNS


def is_plan_file(file_path: str) -> bool:
    """Check if file is in a plan directory."""
    if not file_path.endswith(".md"):
        return False

    patterns = get_plan_patterns()
    for pattern in patterns:
        if pattern in file_path:
            return True

    return False


def find_false_positive_escape_hatches(content: str) -> set:
    """Find all escape hatch comments in the content.

    Escape hatch format:
    <!-- {regex-pattern} match is a false positive for time estimate blocking hook -->

    Returns set of regex patterns that are marked as false positives.
    """
    escape_pattern = r'<!--\s*([^-]+)\s+match is a false positive for time estimate blocking hook\s*-->'
    matches = re.findall(escape_pattern, content, re.IGNORECASE | re.DOTALL)
    return set(m.strip() for m in matches)


def check_for_time_estimates(content: str, file_path: str) -> list:
    """Check content for time estimate patterns.

    Returns list of dicts with keys: line_num, pattern_desc, matched_text, pattern_regex
    """
    false_positives = find_false_positive_escape_hatches(content)
    issues = []
    lines = content.split('\n')

    for line_num, line in enumerate(lines, 1):
        for pattern_regex, pattern_desc in TIME_ESTIMATE_PATTERNS:
            # Skip if this pattern has an escape hatch
            if pattern_regex in false_positives:
                continue

            match = re.search(pattern_regex, line, re.IGNORECASE)
            if match:
                issues.append({
                    'line': line_num,
                    'pattern_desc': pattern_desc,
                    'matched_text': match.group(0),
                    'pattern_regex': pattern_regex,
                })

    # Also check for Timeline section with durations (multiline pattern)
    timeline_pattern = r'##\s+Timeline\s*\n[\s\S]*?(?:hours?|minutes?|days?|weeks?)'
    if 'Timeline' in '##\s+Timeline' or '## Timeline' in content or '## timeline' in content:
        # Find the timeline section
        match = re.search(timeline_pattern, content, re.IGNORECASE)
        if match:
            # Find which line the match starts on
            matched_text = match.group(0)
            position = content.find(matched_text)
            line_num = content[:position].count('\n') + 1

            # Check if already in issues (avoid duplicates)
            if not any(issue['matched_text'] == 'Timeline section with durations' for issue in issues):
                issues.append({
                    'line': line_num,
                    'pattern_desc': 'Timeline section with durations',
                    'matched_text': matched_text.split('\n')[0],  # Just the first line
                    'pattern_regex': timeline_pattern,
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

    # Check if this is a plan file
    file_path = hook_input.get("tool_input", {}).get("file_path", "")
    if not file_path or not is_plan_file(file_path):
        allow_and_exit()

    # Get the content to check
    tool_input = hook_input.get("tool_input", {})

    # For Write operations: check the content being written
    # For Edit operations: read the entire file and apply the edit to check the result
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

    # Check for time estimates
    issues = check_for_time_estimates(content, file_path)

    if not issues:
        allow_and_exit()

    # Build error message with all matched patterns
    error_lines = [
        "",
        "TIME ESTIMATES BLOCKED IN PLAN DOCUMENTS",
        "",
        "Plans should NEVER include time estimates or completion dates.",
        "They are 100% useless context bloat.",
        "",
        "Found time estimate patterns:",
    ]

    for issue in issues:
        matched_text = issue['matched_text'].replace('\n', '\\n')
        error_lines.append(f"  Line {issue['line']}: {issue['pattern_desc']}")
        error_lines.append(f"    {matched_text[:80]}")

    error_lines.extend([
        "",
        "REMOVE ALL TIME REFERENCES before writing.",
        "",
        "Plans should focus on:",
        "- What needs to be done",
        "- Why it's needed",
        "- How to implement it",
        "",
        "Plans should NEVER include:",
        "- When work will be completed",
        "- How long tasks will take",
        "- Timeline estimates",
        "",
        "---",
        "",
        "Configuration:",
        f"  Checked paths: {', '.join(get_plan_patterns())}",
        "  To customize: export PLAN_DIRECTORY_PATTERNS=\"path1/,path2/\"",
        "",
        "Escape hatch (use surgically):",
        "If a pattern match is a false positive (genuinely not a time estimate), add this comment:",
        "  <!-- {regex-pattern} match is a false positive for time estimate blocking hook -->",
        "",
    ])

    # Build and output the block response
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

    print("Running self-tests for php-qa-ci__block-plan-time-estimates...")
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

    # Test 3: Non-plan file (should allow)
    run_test(
        "Non-plan file (src/file.php)",
        {"tool_name": "Write", "tool_input": {"file_path": "src/Controller.php", "content": "Estimated Effort: 2 hours"}},
        expect_allow=True
    )

    # Test 4: Non-markdown file (should allow)
    run_test(
        "Non-markdown file (CLAUDE/Plan/task.txt)",
        {"tool_name": "Write", "tool_input": {"file_path": "CLAUDE/Plan/task.txt", "content": "Estimated Effort: 2 hours"}},
        expect_allow=True
    )

    # Test 5: Plan file without time estimates (should allow)
    run_test(
        "Plan file without time estimates",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/Plan/001-feature.md",
            "content": "## Task\n\nImplement feature X\n\n## Details\n\nWe need to add functionality Y"
        }},
        expect_allow=True
    )

    # Test 6: Block "Estimated Effort: X hours"
    run_test(
        "Block 'Estimated Effort: X hours'",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/plan/task.md",
            "content": "## Task\n\nEstimated Effort: 2 hours\n\nImplement feature"
        }},
        expect_allow=False
    )

    # Test 7: Block "**Estimated Effort**: X days"
    run_test(
        "Block '**Estimated Effort**: X days'",
        {"tool_name": "Write", "tool_input": {
            "file_path": ".claude/plans/feature.md",
            "content": "**Estimated Effort**: 3 days\n\nImplementation plan"
        }},
        expect_allow=False
    )

    # Test 8: Block "Time estimated:"
    run_test(
        "Block 'Time estimated:'",
        {"tool_name": "Write", "tool_input": {
            "file_path": "docs/plans/roadmap.md",
            "content": "Time estimated: 5 hours for completion"
        }},
        expect_allow=False
    )

    # Test 9: Block "Target Completion: YYYY-MM-DD"
    run_test(
        "Block 'Target Completion: date'",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/Plan/sprint.md",
            "content": "Target Completion: 2024-12-31\n\nProject goals"
        }},
        expect_allow=False
    )

    # Test 10: Block "Phase 1: X hours"
    run_test(
        "Block 'Phase 1: X hours'",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/plan/phases.md",
            "content": "- **Phase 1: 2 hours** - Setup\n- **Phase 2: 3 hours** - Implementation"
        }},
        expect_allow=False
    )

    # Test 11: Block Timeline section with durations
    run_test(
        "Block Timeline section with durations",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/Plan/timeline.md",
            "content": "## Timeline\n\n- Week 1: 10 hours\n- Week 2: 15 hours"
        }},
        expect_allow=False
    )

    # Test 12: Case insensitive matching
    run_test(
        "Case insensitive 'ESTIMATED EFFORT'",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/plan/task.md",
            "content": "ESTIMATED EFFORT: 4 HOURS"
        }},
        expect_allow=False
    )

    # Test 13: Edit operation (simulate edit)
    run_test(
        "Edit operation adding time estimate",
        {"tool_name": "Edit", "tool_input": {
            "file_path": "CLAUDE/Plan/feature.md",
            "old_string": "## Details",
            "new_string": "## Details\n\nEstimated Effort: 3 hours"
        }},
        expect_allow=False
    )

    # Test 14: Escape hatch - false positive
    run_test(
        "Escape hatch - false positive comment",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/plan/task.md",
            "content": "<!-- Estimated Effort: .*?hours? match is a false positive for time estimate blocking hook -->\nEstimated Effort: 2 hours (this is actually a quote from the user)"
        }},
        expect_allow=True
    )

    # Test 15: Multiple time estimates in same file
    run_test(
        "Multiple time estimates",
        {"tool_name": "Write", "tool_input": {
            "file_path": "CLAUDE/Plan/multi.md",
            "content": "Estimated Effort: 2 hours\n\nTarget Completion: 2024-12-15\n\nPhase 1: 3 days"
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
