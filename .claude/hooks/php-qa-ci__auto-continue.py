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
import os


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


def make_response(event_name: str, decision: str, reason: str) -> dict:
    """
    Create a properly formatted hook response for the given event type.

    CRITICAL: Different event types have different response schemas!

    Stop events use top-level fields:
        {"continue": bool, "stopReason": str, "decision": "approve"|"block"}

    PreToolUse/PostToolUse use hookSpecificOutput:
        {"hookSpecificOutput": {"hookEventName": str, "permissionDecision": str, ...}}
    """
    if event_name == "Stop":
        # Stop hooks use completely different schema - no hookSpecificOutput!
        # decision: "allow" -> continue=True, "deny" -> continue=False (block stop)
        return {
            "continue": decision == "deny",  # deny = don't stop = continue
            "stopReason": reason,
            "decision": "block" if decision == "deny" else "approve"
        }
    else:
        # PreToolUse, PostToolUse use hookSpecificOutput
        return {
            "hookSpecificOutput": {
                "hookEventName": event_name,
                "permissionDecision": decision,
                "permissionDecisionReason": reason
            }
        }


def output_and_exit(response: dict) -> None:
    """Output JSON response and exit."""
    print(json.dumps(response))
    sys.exit(0)


def process_hook_logic(event_name: str) -> None:
    """
    Core hook logic shared by all event entry points.

    Args:
        event_name: The event type (Stop, PreToolUse, etc.) - passed explicitly
                   by the entry point, NOT inferred.
    """
    # Read hook input from stdin
    try:
        hook_input = json.load(sys.stdin)
    except json.JSONDecodeError:
        # If we can't parse input, allow operation (fail safe)
        output_and_exit(make_response(event_name, "allow", "Hook allows (invalid input)"))
        return

    # Check if we're already in a continuation loop (prevent infinite loops)
    if hook_input.get("stop_hook_active", False):
        output_and_exit(make_response(event_name, "allow", "Hook allows (loop prevention)"))
        return

    # Get transcript path
    transcript_path = hook_input.get("transcript_path")
    if not transcript_path:
        output_and_exit(make_response(event_name, "allow", "Hook allows (no transcript)"))
        return

    # Check if we should auto-continue
    should_continue, reason = should_auto_continue(transcript_path)

    if should_continue:
        # Block the stop and instruct to continue
        output_and_exit(make_response(
            event_name,
            "deny",
            f"Auto-continuing: {reason}. Please continue with the task."
        ))
        return

    # Allow operation
    output_and_exit(make_response(event_name, "allow", "Hook allows this operation"))


# =============================================================================
# DEDICATED ENTRY POINTS FOR EACH HOOK EVENT
#
# Each event type MUST have its own entry point. This ensures the correct
# hookEventName is always returned, matching the event that triggered the hook.
#
# DO NOT try to infer the event type from input - it's unreliable.
# Instead, use symlinks or wrapper scripts to call the correct entry point.
# =============================================================================

def main_stop() -> None:
    """Entry point for Stop event hooks."""
    process_hook_logic("Stop")


def main_pre_tool_use() -> None:
    """Entry point for PreToolUse event hooks."""
    process_hook_logic("PreToolUse")


def main_post_tool_use() -> None:
    """Entry point for PostToolUse event hooks."""
    process_hook_logic("PostToolUse")


def main() -> None:
    """
    Default entry point - determines event from script name or env var.

    IMPORTANT: This hook is ONLY designed for Stop events (auto-continue).
    For PreToolUse/PostToolUse, it silently no-ops to avoid confusing messages.

    Event detection order:
    1. CLAUDE_HOOK_EVENT environment variable (explicit)
    2. Script name suffix (e.g., script--stop.py -> Stop)
    3. Falls back to Stop (this hook's primary purpose)
    """
    # Check environment variable first
    event_from_env = os.environ.get("CLAUDE_HOOK_EVENT")
    if event_from_env:
        # This hook only makes sense for Stop events
        # For other events, silently allow without any message
        if event_from_env != "Stop":
            print("{}")
            sys.exit(0)
        process_hook_logic(event_from_env)
        return

    # Check script name for event suffix
    script_name = os.path.basename(sys.argv[0]) if sys.argv else ""
    if "--stop" in script_name.lower():
        main_stop()
    elif "--pretooluse" in script_name.lower() or "--posttooluse" in script_name.lower():
        # Silently no-op for PreToolUse/PostToolUse - this hook is Stop-only
        print("{}")
        sys.exit(0)
    else:
        # This hook's primary purpose is Stop event - use that as default
        main_stop()


def self_test():
    """Run comprehensive self-tests."""
    import io
    import tempfile

    print("Running self-tests for php-qa-ci__auto-continue...")
    print("=" * 60)

    passed = 0
    failed = 0

    def run_test(name, hook_input, entry_point, expect_allow, expect_event_name):
        """
        Test a specific entry point with given input.

        Args:
            name: Test name
            hook_input: Dict to pass as JSON stdin
            entry_point: Function to call (main_stop, main_pre_tool_use, etc.)
            expect_allow: True if expecting "allow", False for "deny"
            expect_event_name: Expected event type (Stop uses different schema!)
        """
        nonlocal passed, failed
        old_stdout = sys.stdout
        old_stdin = sys.stdin
        sys.stdout = io.StringIO()
        sys.stdin = io.StringIO(json.dumps(hook_input))

        try:
            entry_point()
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

        # Stop events have different schema than PreToolUse/PostToolUse
        if expect_event_name == "Stop":
            # Stop schema: {"continue": bool, "stopReason": str, "decision": str}
            if "continue" not in result:
                print(f"❌ FAIL: {name}")
                print(f"   Missing 'continue' field in Stop response")
                failed += 1
                return

            # For Stop: allow=approve (don't continue), deny=block (do continue)
            # expect_allow=True means we want to allow stopping (continue=False)
            # expect_allow=False means we want to block stopping (continue=True)
            expected_continue = not expect_allow
            if result["continue"] != expected_continue:
                print(f"❌ FAIL: {name}")
                print(f"   Expected continue={expected_continue}, got {result['continue']}")
                failed += 1
                return

            expected_decision = "approve" if expect_allow else "block"
            if result.get("decision") != expected_decision:
                print(f"❌ FAIL: {name}")
                print(f"   Expected decision='{expected_decision}', got '{result.get('decision')}'")
                failed += 1
                return
        else:
            # PreToolUse/PostToolUse schema: {"hookSpecificOutput": {...}}
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

            if decision != expected_decision:
                print(f"❌ FAIL: {name}")
                print(f"   Expected decision {expected_decision}, got {decision}")
                failed += 1
                return

            actual_event_name = hook_output.get("hookEventName")
            if actual_event_name != expect_event_name:
                print(f"❌ FAIL: {name}")
                print(f"   Expected hookEventName '{expect_event_name}', got '{actual_event_name}'")
                failed += 1
                return

        print(f"✓ PASS: {name}")
        passed += 1

    # =========================================================================
    # DEDICATED ENTRY POINT TESTS
    # Each entry point must return the correct hookEventName
    # =========================================================================

    # Test 1: main_stop() returns Stop event name
    run_test(
        "main_stop() returns hookEventName='Stop'",
        {},
        main_stop,
        expect_allow=True,
        expect_event_name="Stop"
    )

    # Test 2: main_pre_tool_use() returns PreToolUse event name
    run_test(
        "main_pre_tool_use() returns hookEventName='PreToolUse'",
        {},
        main_pre_tool_use,
        expect_allow=True,
        expect_event_name="PreToolUse"
    )

    # Test 3: main_post_tool_use() returns PostToolUse event name
    run_test(
        "main_post_tool_use() returns hookEventName='PostToolUse'",
        {},
        main_post_tool_use,
        expect_allow=True,
        expect_event_name="PostToolUse"
    )

    # =========================================================================
    # STOP EVENT LOGIC TESTS (using main_stop entry point)
    # =========================================================================

    # Test 4: Empty input (should allow)
    run_test(
        "Stop: Empty input allows",
        {},
        main_stop,
        expect_allow=True,
        expect_event_name="Stop"
    )

    # Test 5: Missing transcript_path (should allow)
    run_test(
        "Stop: Missing transcript_path allows",
        {"stop_hook_active": False},
        main_stop,
        expect_allow=True,
        expect_event_name="Stop"
    )

    # Test 6: stop_hook_active is True (prevent infinite loop)
    run_test(
        "Stop: stop_hook_active=True prevents loop",
        {"stop_hook_active": True, "transcript_path": "/tmp/transcript.json"},
        main_stop,
        expect_allow=True,
        expect_event_name="Stop"
    )

    # Test 7: No assistant message in transcript
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [{"role": "user", "content": "Hello"}]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: No assistant message allows",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=True,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # Test 8: Assistant message without continue prompt
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [
            {"role": "user", "content": "What's the weather?"},
            {"role": "assistant", "content": "I don't know the weather."}
        ]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: No continue prompt allows",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=True,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # Test 9: "Would you like to continue" - should DENY (auto-continue)
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [
            {"role": "user", "content": "Please do the task"},
            {"role": "assistant", "content": "I've completed part 1. Would you like to continue?"}
        ]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: 'would you like to continue' denies",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=False,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # Test 10: "Shall I proceed" - should DENY
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [
            {"role": "assistant", "content": "Setup complete. Shall I proceed with the implementation?"}
        ]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: 'shall I proceed' denies",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=False,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # Test 11: Structured content with continue prompt
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [{
            "role": "assistant",
            "content": [
                {"type": "text", "text": "First part complete."},
                {"type": "text", "text": "Should I continue with next step?"}
            ]
        }]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: Structured content continue prompt denies",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=False,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # Test 12: Case insensitive matching
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [
            {"role": "assistant", "content": "Done! WOULD YOU LIKE ME TO CONTINUE?"}
        ]}, f)
        transcript_path = f.name
    try:
        run_test(
            "Stop: Case insensitive pattern denies",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_stop,
            expect_allow=False,
            expect_event_name="Stop"
        )
    finally:
        os.unlink(transcript_path)

    # =========================================================================
    # PRETOOLUSE ENTRY POINT TESTS (verifies same logic, different event name)
    # =========================================================================

    # Test 13: PreToolUse with continue prompt still returns PreToolUse event
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
        json.dump({"messages": [
            {"role": "assistant", "content": "Would you like me to continue?"}
        ]}, f)
        transcript_path = f.name
    try:
        run_test(
            "PreToolUse: Continue prompt returns PreToolUse event",
            {"transcript_path": transcript_path, "stop_hook_active": False},
            main_pre_tool_use,
            expect_allow=False,
            expect_event_name="PreToolUse"
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
