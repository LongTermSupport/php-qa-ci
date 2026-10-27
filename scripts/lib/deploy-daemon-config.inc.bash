# deploy-skills phase: hooks-daemon config enforcement + classic-hook teardown.
#
# Sourced by scripts/deploy-skills.bash. Relies on orchestrator-established
# variables: DAEMON_DETECTED, DAEMON_CONFIG, HOOKS_TARGET, SETTINGS_FILE,
# DAEMON_INSTALL_REF, QACI_PATH, PROJECT_ROOT. When the daemon is present this
# enforces the required handler config (via bin/hooks-daemon-required-handlers),
# declares subagent_full_qa_blocker (via bin/hooks-daemon-full-qa-blocker) and
# removes classic hooks + their settings.json registrations; otherwise it prints
# the install guidance.

# ============================================================================
# Phase 4: hooks-daemon Config Enforcement
# ============================================================================
# Ensure projects using php-qa-ci have required daemon handlers configured

# Note: DAEMON_CONFIG already set earlier with monorepo detection

if [[ "$DAEMON_DETECTED" == "true" ]]; then
    echo ""
    echo "📋 hooks-daemon detected - enforcing required handler configuration..."

    # The enforcement is a comment-preserving text edit in PHP
    # (LTS\PHPQA\HooksDaemon\RequiredHandlersEnforcer): a config that is already
    # right is not touched, a differing setting changes only its own line, and a
    # layout that cannot be edited in place is left as it is with a message
    # naming what to set by hand. It needs no daemon venv, so it runs in any
    # environment; none of it may fail the deploy.
    REQUIRED_HANDLERS_RC=0
    "${PHP_BINARY:-php}" "$QACI_PATH/bin/hooks-daemon-required-handlers" "$DAEMON_CONFIG" || REQUIRED_HANDLERS_RC=$?
    if [[ $REQUIRED_HANDLERS_RC -ne 0 ]]; then
        echo "  ⚠️  required handler enforcement did not complete (exit $REQUIRED_HANDLERS_RC) — continuing deployment" >&2
    else
        echo "  ✓ hooks-daemon configuration enforced"
    fi

    # Lint-override advisory (grep-based, no venv needed): the daemon's default
    # extended PHP lint hits php-qa-ci's phpstan redirect stub. Prints the exact
    # YAML fix for a human/agent to apply; never edits the YAML, never fails.
    # shellcheck source=scripts/lib/daemon-lint-override-check.inc.bash
    source "$QACI_PATH/scripts/lib/daemon-lint-override-check.inc.bash"
    phpQaCiDaemonLintOverrideCheck "$DAEMON_CONFIG"

    # subagent_full_qa_blocker (daemon v3.67.0+): the full pipeline is the
    # coordinating session's gate, so a sub-agent's run is denied. Runs after
    # the enforcement above, which may rewrite the file. The version gate, the
    # comment-preserving edit and the config-validate check are PHP
    # (LTS\PHPQA\HooksDaemon\FullQaBlockerConfigurator); none of it may fail
    # the deploy.
    echo ""
    echo "🛡️  hooks-daemon subagent_full_qa_blocker (full pipeline reserved for the coordinating session)..."
    BLOCKER_RC=0
    "${PHP_BINARY:-php}" "$QACI_PATH/bin/hooks-daemon-full-qa-blocker" "$PROJECT_ROOT" "$DAEMON_CONFIG" || BLOCKER_RC=$?
    if [[ $BLOCKER_RC -ne 0 ]]; then
        echo "  ⚠️  subagent_full_qa_blocker step failed with $("${PHP_BINARY:-php}" -d display_errors=stderr "$QACI_PATH/bin/describe-exit" --php-qa-ci-helper "$BLOCKER_RC") — continuing deployment" >&2
    fi

    # ========================================================================
    # Remove classic hooks - daemon provides all functionality now
    # ========================================================================
    echo ""
    echo "🧹 Removing classic hooks (daemon provides functionality)..."

    # Remove hook files (if they exist)
    FILES_REMOVED=0
    for hook_file in "$HOOKS_TARGET"/php-qa-ci__*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            rm -f "${hook_file:?}"
            echo "  ✓ Removed file: $hook_name"
            FILES_REMOVED=$((FILES_REMOVED + 1))
        fi
    done

    if [[ $FILES_REMOVED -eq 0 ]]; then
        echo "  ℹ️  No php-qa-ci hook files found to remove (already clean)"
    fi

    # ALWAYS clean up settings.json - registrations may exist even if files are gone
    if [[ -f "$SETTINGS_FILE" ]]; then
        echo ""
        echo "  Checking settings.json for php-qa-ci hook registrations..."

        python3 - "$SETTINGS_FILE" << 'PYTHON_CLEANUP'
import json
import sys

settings_file = sys.argv[1]

try:
    with open(settings_file, 'r') as f:
        settings = json.load(f)

    # Get hooks config
    hooks_config = settings.get('hooks', {})
    changes_made = False
    total_removed = 0

    # Check all hook sections for any php-qa-ci__ hooks
    for hook_type in ['PreToolUse', 'PostToolUse', 'Stop', 'SessionStart', 'SessionEnd',
                      'SubagentStop', 'UserPromptSubmit', 'Notification', 'PermissionRequest', 'PreCompact']:
        hooks_section = hooks_config.get(hook_type, [])
        if not hooks_section:
            continue

        hooks_list = hooks_section[0].get('hooks', [])
        original_count = len(hooks_list)

        # Remove ANY hook with php-qa-ci__ in the command
        # These are classic hooks that should be removed when daemon is active
        hooks_list[:] = [
            h for h in hooks_list
            if 'php-qa-ci__' not in h.get('command', '')
        ]

        new_count = len(hooks_list)
        if new_count < original_count:
            removed_count = original_count - new_count
            total_removed += removed_count
            print(f"    ✓ Removed {removed_count} php-qa-ci hook(s) from {hook_type}")
            changes_made = True

        hooks_section[0]['hooks'] = hooks_list

    # Write back if changes made
    if changes_made:
        new_content = json.dumps(settings, indent=2) + '\n'
        with open(settings_file, 'w') as f:
            f.write(new_content)
        print(f"  ✅ settings.json cleaned up ({total_removed} registration(s) removed)")
    else:
        print("  ✅ settings.json already clean (no php-qa-ci hook registrations found)")

except Exception as e:
    print(f"  ⚠️  ERROR: Could not update settings.json: {e}", file=sys.stderr)
    print(f"  ⚠️  ACTION REQUIRED: Manually remove php-qa-ci__ hooks from {settings_file}", file=sys.stderr)
    sys.exit(1)

PYTHON_CLEANUP
    else
        echo "  ⚠️  WARNING: settings.json not found at $SETTINGS_FILE"
        echo "  If you have php-qa-ci hook registrations, they should be removed manually"
    fi

    echo ""
    echo "✅ Classic hooks removed - hooks-daemon now provides all functionality"
else
    echo ""
    echo "ℹ️  hooks-daemon not detected"
    echo ""
    echo "  php-qa-ci hooks require hooks-daemon to function."
    echo "  Classic .claude/hooks/*.py files have been deployed but won't run without daemon."
    echo ""
    echo "  To install hooks-daemon:"
    echo "    git clone -b $DAEMON_INSTALL_REF https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon.git .claude/hooks-daemon"
    echo "    cd .claude/hooks-daemon"
    echo "    python3 -m venv untracked/venv && untracked/venv/bin/pip install -e ."
    echo "    untracked/venv/bin/python install.py"
    echo ""
    echo "  Or see: https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon"
    echo ""
fi
