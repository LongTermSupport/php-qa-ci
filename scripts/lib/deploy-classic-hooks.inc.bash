# deploy-skills phase: classic .py hooks — copy, legacy-name migration, and
# settings.json registration.
#
# Sourced by scripts/deploy-skills.bash. Relies on orchestrator-established
# variables: DAEMON_DETECTED, HOOKS_SOURCE/HOOKS_TARGET, PROJECT_ROOT,
# SETTINGS_FILE, and install_owned_file. Copy and registration are daemon-gated
# (DAEMON_DETECTED == "false"); the legacy-name migration runs whenever a
# settings.json exists, so a consumer upgrading from the old un-prefixed hook
# names is repaired regardless of daemon state.

# Copy each hook (Python scripts) - ONLY if daemon not detected
if [[ "$DAEMON_DETECTED" == "false" ]] && [[ -d "$HOOKS_SOURCE" ]]; then
    for hook_file in "$HOOKS_SOURCE"/*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            echo "  Installing hook: $hook_name"
            install_owned_file "$hook_file" "$HOOKS_TARGET/$hook_name" "hook '$hook_name'"
            chmod +x "$HOOKS_TARGET/$hook_name"
        fi
    done
fi

# Migrate old hook names to new php-qa-ci__ prefix
if [[ -f "$PROJECT_ROOT/.claude/settings.json" ]]; then
    echo "  Migrating hook registrations..."
    python3 << 'PYTHON_MIGRATION'
import json
import sys
from pathlib import Path

settings_file = Path(".claude/settings.json")
if not settings_file.exists():
    sys.exit(0)

# Old name -> New name mapping
HOOK_MIGRATIONS = {
    ".claude/hooks/auto-continue.py": ".claude/hooks/php-qa-ci__auto-continue.py",
    ".claude/hooks/prevent-destructive-git.py": ".claude/hooks/php-qa-ci__prevent-destructive-git.py",
    ".claude/hooks/discourage-git-stash.py": ".claude/hooks/php-qa-ci__discourage-git-stash.py",
    ".claude/hooks/block-plan-time-estimates.py": ".claude/hooks/php-qa-ci__block-plan-time-estimates.py",
    ".claude/hooks/validate-claude-readme-content.py": ".claude/hooks/php-qa-ci__validate-claude-readme-content.py",
    ".claude/hooks/enforce-markdown-organization.py": ".claude/hooks/php-qa-ci__enforce-markdown-organization.py",
}

try:
    with open(settings_file, 'r') as f:
        settings = json.load(f)

    # Migrate old names to new names across ALL hook sections
    migrated = False
    hooks_config = settings.get("hooks", {})

    # Check all hook types: PreToolUse, PostToolUse, Stop
    for hook_type in ["PreToolUse", "PostToolUse", "Stop"]:
        hooks_section = hooks_config.get(hook_type, [])
        if not hooks_section:
            continue

        hooks_list = hooks_section[0].get("hooks", [])
        for hook in hooks_list:
            if hook.get("command") in HOOK_MIGRATIONS:
                old_cmd = hook["command"]
                hook["command"] = HOOK_MIGRATIONS[old_cmd]
                print(f"    Migrated ({hook_type}): {old_cmd} -> {hook['command']}")
                migrated = True

    # Write back if any migrations occurred
    if migrated:
        new_content = json.dumps(settings, indent=2) + '\n'
        with open(settings_file, 'w') as f:
            f.write(new_content)
        print("  Hook migration complete!")

        # Delete old hook files after successful migration
        print("  Cleaning up old hook files...")
        import os
        for old_hook_path in HOOK_MIGRATIONS.keys():
            old_file = Path(old_hook_path)
            if old_file.exists():
                try:
                    old_file.unlink()
                    print(f"    Removed: {old_hook_path}")
                except Exception as e:
                    print(f"    Warning: Could not remove {old_hook_path}: {e}", file=sys.stderr)
    else:
        print("  No hook migrations needed")
except Exception as e:
    print(f"  Warning: Could not migrate hooks: {e}", file=sys.stderr)

PYTHON_MIGRATION
fi

# Register hooks in settings.json — ONLY when the daemon is not detected,
# matching the copy gate above. Registering classic hooks under a daemon setup
# only for Phase 4 to undo them left a register-then-undo window: an
# interrupted run stranded settings.json entries pointing at hooks that were
# never copied.
if [[ "$DAEMON_DETECTED" == "false" ]] && [[ -d "$HOOKS_SOURCE" ]] && compgen -G "$HOOKS_SOURCE/*.py" > /dev/null; then
    echo "  Registering hooks in settings.json..."

    # Create settings.json if it doesn't exist
    if [[ ! -f "$SETTINGS_FILE" ]]; then
        echo '{}' > "$SETTINGS_FILE"
    fi

    # Build list of hooks to register
    HOOKS_TO_REGISTER=()
    for hook_file in "$HOOKS_SOURCE"/*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            HOOKS_TO_REGISTER+=(".claude/hooks/$hook_name")
        fi
    done

    # Use Python to merge hooks into settings.json
    python3 - "$SETTINGS_FILE" "${HOOKS_TO_REGISTER[@]}" << 'PYTHON_SCRIPT'
import json
import sys

settings_file = sys.argv[1]
hooks_to_add = sys.argv[2:]

# Hooks that should ONLY be registered in Stop event (not PreToolUse)
STOP_ONLY_HOOKS = {
    ".claude/hooks/php-qa-ci__auto-continue.py",
}

# Load existing settings
try:
    with open(settings_file, 'r') as f:
        settings = json.load(f)
except (json.JSONDecodeError, FileNotFoundError):
    settings = {}

# Ensure hooks structure exists
if 'hooks' not in settings:
    settings['hooks'] = {}
if 'PreToolUse' not in settings['hooks']:
    settings['hooks']['PreToolUse'] = [{'hooks': []}]
if 'Stop' not in settings['hooks']:
    settings['hooks']['Stop'] = [{'hooks': []}]

# Get existing PreToolUse hooks list
pre_tool_use = settings['hooks']['PreToolUse']
if not pre_tool_use:
    pre_tool_use = [{'hooks': []}]
    settings['hooks']['PreToolUse'] = pre_tool_use
pre_hooks_list = pre_tool_use[0].get('hooks', [])

# Get existing Stop hooks list
stop_hooks = settings['hooks']['Stop']
if not stop_hooks:
    stop_hooks = [{'hooks': []}]
    settings['hooks']['Stop'] = stop_hooks
stop_hooks_list = stop_hooks[0].get('hooks', [])

# Get existing hook commands (check both raw command and with env prefix)
pre_existing_commands = {h.get('command') for h in pre_hooks_list if isinstance(h, dict)}
stop_existing_commands = {h.get('command') for h in stop_hooks_list if isinstance(h, dict)}

# Remove any Stop-only hooks that were incorrectly added to PreToolUse
pre_hooks_list = [h for h in pre_hooks_list if h.get('command') not in STOP_ONLY_HOOKS]

# Add new hooks to appropriate sections
for hook_path in hooks_to_add:
    if hook_path in STOP_ONLY_HOOKS:
        # Stop-only hooks get CLAUDE_HOOK_EVENT=Stop prefix
        stop_command = f"CLAUDE_HOOK_EVENT=Stop {hook_path}"
        if stop_command not in stop_existing_commands and hook_path not in stop_existing_commands:
            stop_hooks_list.append({
                'type': 'command',
                'command': stop_command,
                'timeout': 5
            })
            print(f"    Registered (Stop): {stop_command}")
        else:
            print(f"    Already registered (Stop): {hook_path}")
    else:
        # Regular hooks go to PreToolUse
        if hook_path not in pre_existing_commands:
            pre_hooks_list.append({
                'type': 'command',
                'command': hook_path,
                'timeout': 5
            })
            print(f"    Registered (PreToolUse): {hook_path}")
        else:
            print(f"    Already registered (PreToolUse): {hook_path}")

pre_tool_use[0]['hooks'] = pre_hooks_list
stop_hooks[0]['hooks'] = stop_hooks_list

# Only write back if content actually changed
new_content = json.dumps(settings, indent=2) + '\n'
try:
    with open(settings_file, 'r') as f:
        existing_content = f.read()
except FileNotFoundError:
    existing_content = ''

if new_content != existing_content:
    with open(settings_file, 'w') as f:
        f.write(new_content)
    print("  settings.json updated")
else:
    print("  settings.json unchanged - skipping write")

PYTHON_SCRIPT
fi
