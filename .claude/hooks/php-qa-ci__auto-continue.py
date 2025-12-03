#!/usr/bin/env python3
"""
PHP-QA-CI Deployed Hook

This hook is automatically deployed from vendor/lts/php-qa-ci/.claude/hooks/
by the Composer plugin during `composer install/update`.

Changes to this file will be overwritten on next composer operation.

Full documentation: vendor/lts/php-qa-ci/.claude/hooks/README.md
Package documentation: vendor/lts/php-qa-ci/CLAUDE.md

================================================================================

Claude Code Hook: Auto-Continue

Stop hook that detects "would you like to continue" type messages and
automatically continues execution to prevent unnecessary user interaction.

This prevents Claude from stopping to ask permission when it should
just keep working on the task.

================================================================================
"""

import json
import sys
import re


# Patterns that indicate Claude is asking to continue
CONTINUE_PATTERNS = [
    r"would you like (?:me )?to continue",
    r"shall i (?:continue|proceed)",
    r"do you want me to (?:continue|proceed)",
    r"should i (?:continue|proceed)",
    r"ready to (?:continue|proceed)",
    r"let me know if you.*(?:continue|proceed)",
    r"want me to (?:go ahead|keep going)",
    r"if you'd like.*(?:continue|proceed)",
    r"i can (?:continue|proceed) with",
]


def should_auto_continue(transcript_path: str) -> tuple[bool, str]:
    """
    Check transcript to see if last assistant message asks to continue.

    Returns:
        tuple of (should_continue, reason)
    """
    try:
        with open(transcript_path, 'r') as f:
            transcript = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return False, "Could not read transcript"

    # Get messages from transcript
    messages = transcript.get('messages', [])
    if not messages:
        return False, "No messages in transcript"

    # Find last assistant message
    last_assistant_msg = None
    for msg in reversed(messages):
        if msg.get('role') == 'assistant':
            last_assistant_msg = msg
            break

    if not last_assistant_msg:
        return False, "No assistant message found"

    # Extract text content from message
    content = last_assistant_msg.get('content', '')
    if isinstance(content, list):
        # Handle structured content (text blocks)
        text_parts = []
        for part in content:
            if isinstance(part, dict) and part.get('type') == 'text':
                text_parts.append(part.get('text', ''))
            elif isinstance(part, str):
                text_parts.append(part)
        content = ' '.join(text_parts)

    content_lower = content.lower()

    # Check for continue patterns
    for pattern in CONTINUE_PATTERNS:
        if re.search(pattern, content_lower):
            return True, f"Detected continue prompt: {pattern}"

    return False, "No continue prompt detected"


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
        # If we can't parse input, allow stop (fail safe)
        allow_and_exit()

    # Check if we're already in a continuation loop (prevent infinite loops)
    if hook_input.get("stop_hook_active", False):
        allow_and_exit()

    # Get transcript path
    transcript_path = hook_input.get("transcript_path")
    if not transcript_path:
        allow_and_exit()

    # Check if we should auto-continue
    should_continue, reason = should_auto_continue(transcript_path)

    if should_continue:
        # Block the stop and instruct to continue
        result = {
            "hookSpecificOutput": {
                "hookEventName": "PreToolUse",
                "permissionDecision": "deny",
                "permissionDecisionReason": f"Auto-continuing: {reason}. Please continue with the task."
            }
        }
        print(json.dumps(result))
        sys.exit(0)

    # Allow stop
    allow_and_exit()


if __name__ == "__main__":
    main()
