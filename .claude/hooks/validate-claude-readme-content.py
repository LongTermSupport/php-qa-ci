#!/usr/bin/env python3
"""
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
"""

import json
import re
import sys
from pathlib import Path


# Blocked patterns that indicate logs/research instead of instructions
BLOCKED_PATTERNS = [
    # Implementation logs - present tense action verbs with objects
    (r'\b(?:created|added|modified|updated|implemented|built|generated)\s+[a-z0-9_\-./]+', 'Implementation log'),
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
    """Output empty JSON and exit - prevents 'hook error' messages."""
    print('{}')
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


if __name__ == "__main__":
    main()
