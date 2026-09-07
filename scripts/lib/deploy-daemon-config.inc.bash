# deploy-skills phase: hooks-daemon config enforcement + classic-hook teardown.
#
# Sourced by scripts/deploy-skills.bash. Relies on orchestrator-established
# variables: DAEMON_DETECTED, DAEMON_CONFIG, PYTHON3_YAML, HOOKS_TARGET,
# SETTINGS_FILE, DAEMON_INSTALL_REF. When the daemon is present this enforces the
# required handler config (via the daemon venv python) and removes classic hooks
# + their settings.json registrations; otherwise it prints the install guidance.

# ============================================================================
# Phase 4: hooks-daemon Config Enforcement
# ============================================================================
# Ensure projects using php-qa-ci have required daemon handlers configured

# Note: DAEMON_CONFIG already set earlier with monorepo detection

if [[ "$DAEMON_DETECTED" == "true" ]]; then
    echo ""
    echo "📋 hooks-daemon detected - enforcing required handler configuration..."

    # Daemon YAML handler enforcement requires a daemon venv Python that has
    # pyyaml installed. That venv is host/platform-specific: when composer runs
    # inside a container that bind-mounts a host-built venv, the venv's python
    # symlink points at a host path (e.g. a Homebrew python) that does not exist
    # in the container, so the venv is unusable here.
    #
    # In that situation we DO NOT fail the deployment. The daemon validates and
    # enforces its own config where it actually runs (the host). We skip only
    # the YAML enforcement step and still perform the classic-hook cleanup below
    # (which uses system python3 and needs no venv) so settings.json is left in
    # the correct daemon-managed state regardless of environment.
    if [[ -z "$PYTHON3_YAML" ]]; then
        echo "  ⚠️  hooks daemon venv not usable in this environment — skipping daemon YAML handler enforcement." >&2
        echo "     This is expected when composer runs inside a container that mounts a host-built" >&2
        echo "     daemon venv. The daemon enforces its own config where it runs (typically the host)." >&2
        echo "     To enforce here too, install/upgrade the daemon (v3.9.0+) so a usable venv exists" >&2
        echo "     in this environment." >&2
    else
        # Use daemon venv Python (has pyyaml) - system Python is never used
        $PYTHON3_YAML - "$DAEMON_CONFIG" << 'PYTHON_DAEMON_CONFIG'
import sys
from pathlib import Path

# Try to import yaml - gracefully handle if not available
try:
    import yaml
    HAS_YAML = True
except ImportError:
    HAS_YAML = False

config_file = Path(sys.argv[1])

if not HAS_YAML:
    print("  ❌ ERROR: PyYAML not available in hooks daemon venv", file=sys.stderr)
    print("  The daemon venv is missing pyyaml — the daemon needs to be upgraded.", file=sys.stderr)
    print("  Upgrade hooks daemon (v3.9.0+):", file=sys.stderr)
    print("    curl -fsSL https://raw.githubusercontent.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon/main/scripts/upgrade.sh -o /tmp/upgrade.sh", file=sys.stderr)
    print("    bash /tmp/upgrade.sh --project-root <project-root>", file=sys.stderr)
    sys.exit(1)

# Required handlers for php-qa-ci projects
REQUIRED_HANDLERS = {
    "git_stash": {
        "enabled": True,
        "options": {"mode": "deny"},
    },
    "plan_time_estimates": {
        "enabled": True
    },
    "validate_instruction_content": {
        "enabled": True
    },
    "markdown_organization": {
        "enabled": True
    }
}

try:
    # Load existing config
    with open(config_file, 'r') as f:
        config = yaml.safe_load(f) or {}

    # Ensure structure exists
    if 'handlers' not in config:
        config['handlers'] = {}
    if 'pre_tool_use' not in config['handlers']:
        config['handlers']['pre_tool_use'] = {}

    pre_tool_use = config['handlers']['pre_tool_use']
    changes_made = []

    # Enforce each required handler
    for handler_name, required_config in REQUIRED_HANDLERS.items():
        if handler_name not in pre_tool_use:
            # Handler not configured at all - add it
            pre_tool_use[handler_name] = required_config
            changes_made.append(f"  ✓ Added handler: {handler_name}")
        else:
            # Handler exists - verify configuration
            existing = pre_tool_use[handler_name]
            if not isinstance(existing, dict):
                # Handler is not a dict (maybe just enabled: true) - fix it
                pre_tool_use[handler_name] = required_config
                changes_made.append(f"  ✓ Updated handler: {handler_name}")
            else:
                # Check each required key
                for key, value in required_config.items():
                    if key not in existing:
                        existing[key] = value
                        changes_made.append(f"  ✓ Added {handler_name}.{key}: {value}")
                    elif existing[key] != value:
                        old_value = existing[key]
                        existing[key] = value
                        changes_made.append(f"  ✓ Updated {handler_name}.{key}: {old_value} → {value}")

    if changes_made:
        # Write back updated config
        with open(config_file, 'w') as f:
            yaml.dump(config, f, default_flow_style=False, sort_keys=False)

        print("  Configuration updated:")
        for change in changes_made:
            print(change)
    else:
        print("  ✓ All required handlers already configured correctly")

except Exception as e:
    print(f"  ⚠️  Warning: Could not validate daemon config: {e}", file=sys.stderr)
    print("  Continuing deployment...", file=sys.stderr)

PYTHON_DAEMON_CONFIG

        echo "  ✓ hooks-daemon configuration enforced"
    fi

    # Lint-override advisory (grep-based, no venv needed): the daemon's default
    # extended PHP lint hits php-qa-ci's phpstan redirect stub. Prints the exact
    # YAML fix for a human/agent to apply; never edits the YAML, never fails.
    # shellcheck source=scripts/lib/daemon-lint-override-check.inc.bash
    source "$QACI_PATH/scripts/lib/daemon-lint-override-check.inc.bash"
    phpQaCiDaemonLintOverrideCheck "$DAEMON_CONFIG"

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
