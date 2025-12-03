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


def self_test():
    """Run comprehensive self-tests."""
    import io
    import tempfile
    import os

    print("Running self-tests for php-qa-ci__auto-continue...")
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
                print(f"   Reason: {hook_output.get('permissionDecisionReason', 'N/A')[:80]}")
            failed += 1

    # Test 1: Invalid JSON input (should allow - fail open)
    # This test requires special handling since None won't work
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
            print(f"✓ PASS: Invalid JSON input (fail open)")
            passed += 1
        else:
            print(f"❌ FAIL: Invalid JSON input (fail open)")
            failed += 1
    except:
        print(f"❌ FAIL: Invalid JSON input (fail open)")
        failed += 1

    # Test 2: Empty input (should allow)
    run_test(
        "Empty input",
        {},
        expect_allow=True
    )

    # Test 3: Missing transcript_path (should allow)
    run_test(
        "Missing transcript_path",
        {"stop_hook_active": False},
        expect_allow=True
    )

    # Test 4: stop_hook_active is True (prevent infinite loop)
    run_test(
        "stop_hook_active=True (prevent loop)",
        {"stop_hook_active": True, "transcript_path": "/tmp/transcript.json"},
        expect_allow=True
    )

    # Test 5: No assistant message in transcript
    # Create temporary transcript file
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "user", "content": "Hello"}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "No assistant message in transcript",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=True
        )
    finally:
        os.unlink(transcript_path)

    # Test 6: Assistant message without continue prompt
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "user", "content": "What's the weather?"},
                {"role": "assistant", "content": "I don't know the weather."}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "No continue prompt detected",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=True
        )
    finally:
        os.unlink(transcript_path)

    # Test 7: "Would you like to continue" - should DENY (auto-continue)
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "user", "content": "Please do the task"},
                {"role": "assistant", "content": "I've completed part 1. Would you like to continue?"}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "Detect 'would you like to continue'",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=False  # Should DENY to auto-continue
        )
    finally:
        os.unlink(transcript_path)

    # Test 8: "Shall I proceed" - should DENY
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "assistant", "content": "Setup complete. Shall I proceed with the implementation?"}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "Detect 'shall I proceed'",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=False
        )
    finally:
        os.unlink(transcript_path)

    # Test 9: "Ready to continue" - should DENY
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "assistant", "content": "All tests passing. Ready to continue with deployment?"}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "Detect 'ready to continue'",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=False
        )
    finally:
        os.unlink(transcript_path)

    # Test 10: Structured content (list of dicts with text blocks)
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {
                    "role": "assistant",
                    "content": [
                        {"type": "text", "text": "First part complete."},
                        {"type": "text", "text": "Should I continue with next step?"}
                    ]
                }
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "Detect continue prompt in structured content",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=False
        )
    finally:
        os.unlink(transcript_path)

    # Test 11: Case insensitive matching
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        transcript = {
            "messages": [
                {"role": "assistant", "content": "Done! WOULD YOU LIKE ME TO CONTINUE?"}
            ]
        }
        json.dump(transcript, f)
        transcript_path = f.name

    try:
        run_test(
            "Case insensitive pattern matching",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            expect_allow=False
        )
    finally:
        os.unlink(transcript_path)

    print("=" * 60)
    print(f"Results: {passed} passed, {failed} failed")
    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        self_test()
    else:
        main()
