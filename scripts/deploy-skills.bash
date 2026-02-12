#!/usr/bin/env bash

set -euo pipefail

QACI_PATH="${1:-}"
PROJECT_ROOT="${2:-}"

if [[ -z "$QACI_PATH" || -z "$PROJECT_ROOT" ]]; then
    echo "Usage: $0 <qaci-path> <project-root>"
    exit 1
fi

SKILLS_SOURCE="$QACI_PATH/.claude/skills"
SKILLS_TARGET="$PROJECT_ROOT/.claude/skills"
AGENTS_SOURCE="$QACI_PATH/.claude/agents"
AGENTS_TARGET="$PROJECT_ROOT/.claude/agents"
HOOKS_SOURCE="$QACI_PATH/.claude/hooks"
HOOKS_TARGET="$PROJECT_ROOT/.claude/hooks"
# Check if hooks-daemon is present
# Support monorepo: check both project root and parent directory
DAEMON_DETECTED=false
DAEMON_CONFIG="$PROJECT_ROOT/.claude/hooks-daemon.yaml"

if [[ -f "$DAEMON_CONFIG" ]]; then
    DAEMON_DETECTED=true
    echo "  📋 Detected hooks-daemon at: $DAEMON_CONFIG"
elif [[ -f "$(dirname "$PROJECT_ROOT")/.claude/hooks-daemon.yaml" ]]; then
    DAEMON_DETECTED=true
    DAEMON_CONFIG="$(dirname "$PROJECT_ROOT")/.claude/hooks-daemon.yaml"
    echo "  📋 Detected hooks-daemon at parent: $DAEMON_CONFIG (monorepo)"
fi

echo "Deploying Skills from: $SKILLS_SOURCE"
echo "                   to: $SKILLS_TARGET"
echo "Deploying Agents from: $AGENTS_SOURCE"
echo "                   to: $AGENTS_TARGET"

if [[ "$DAEMON_DETECTED" == "true" ]]; then
    echo ""
    echo "📋 hooks-daemon detected - will configure daemon instead of deploying classic hooks"
else
    echo "Deploying Hooks from:  $HOOKS_SOURCE"
    echo "                   to: $HOOKS_TARGET"
fi

# Create .claude directories
mkdir -p "$SKILLS_TARGET"
mkdir -p "$AGENTS_TARGET"
mkdir -p "$HOOKS_TARGET"

# Copy each skill
if [[ -d "$SKILLS_SOURCE" ]]; then
    for skill_dir in "$SKILLS_SOURCE"/*; do
        if [[ -d "$skill_dir" ]]; then
            skill_name=$(basename "$skill_dir")
            echo "  Installing skill: $skill_name"

            # Remove existing skill directory to prevent nested copying
            rm -rf "$SKILLS_TARGET/$skill_name"
            # Copy skill directory
            cp -r "$skill_dir" "$SKILLS_TARGET/$skill_name"

            # Make scripts executable
            if [[ -d "$SKILLS_TARGET/$skill_name/scripts" ]]; then
                chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.py 2>/dev/null || true
                chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.bash 2>/dev/null || true
            fi
        fi
    done
fi

# Copy each agent (just markdown files)
if [[ -d "$AGENTS_SOURCE" ]]; then
    for agent_file in "$AGENTS_SOURCE"/*.md; do
        if [[ -f "$agent_file" ]]; then
            agent_name=$(basename "$agent_file")
            echo "  Installing agent: $agent_name"
            cp "$agent_file" "$AGENTS_TARGET/$agent_name"
        fi
    done
fi

# Copy each hook (Python scripts) - ONLY if daemon not detected
if [[ "$DAEMON_DETECTED" == "false" ]] && [[ -d "$HOOKS_SOURCE" ]]; then
    for hook_file in "$HOOKS_SOURCE"/*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            echo "  Installing hook: $hook_name"
            cp "$hook_file" "$HOOKS_TARGET/$hook_name"
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
        with open(settings_file, 'w') as f:
            json.dump(settings, f, indent=2)
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

# Register hooks in settings.json
SETTINGS_FILE="$PROJECT_ROOT/.claude/settings.json"
if [[ -d "$HOOKS_SOURCE" ]] && ls "$HOOKS_SOURCE"/*.py 1>/dev/null 2>&1; then
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

# Write back
with open(settings_file, 'w') as f:
    json.dump(settings, f, indent=2)

PYTHON_SCRIPT
fi

# ============================================================================
# Phase 4: hooks-daemon Config Enforcement
# ============================================================================
# Ensure projects using php-qa-ci have required daemon handlers configured

# Note: DAEMON_CONFIG already set earlier with monorepo detection

if [[ -f "$DAEMON_CONFIG" ]]; then
    echo ""
    echo "📋 hooks-daemon detected - enforcing required handler configuration..."

    # Use Python with PyYAML to validate and update config
    python3 - "$DAEMON_CONFIG" << 'PYTHON_DAEMON_CONFIG'
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
    print("  ⚠️  Warning: PyYAML not installed - cannot validate daemon config", file=sys.stderr)
    print("  Install with: pip install pyyaml", file=sys.stderr)
    print("  Continuing deployment without config validation...", file=sys.stderr)
    sys.exit(0)

# Required handlers for php-qa-ci projects
REQUIRED_HANDLERS = {
    "git_stash": {
        "enabled": True,
        "mode": "deny"  # Strict for php-qa-ci projects
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

    # ========================================================================
    # Remove classic hooks - daemon provides all functionality now
    # ========================================================================
    echo ""
    echo "🧹 Removing classic hooks (daemon provides functionality)..."

    # Remove hook files
    HOOKS_REMOVED=()
    for hook_file in "$HOOKS_TARGET"/php-qa-ci__*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            rm -f "$hook_file"
            HOOKS_REMOVED+=("$hook_name")
            echo "  ✓ Removed: $hook_name"
        fi
    done

    # Update settings.json to remove hook registrations
    if [[ -f "$SETTINGS_FILE" ]] && [[ ${#HOOKS_REMOVED[@]} -gt 0 ]]; then
        echo ""
        echo "  Updating settings.json to remove hook registrations..."

        python3 - "$SETTINGS_FILE" "${HOOKS_REMOVED[@]}" << 'PYTHON_CLEANUP'
import json
import sys

settings_file = sys.argv[1]
hooks_removed = sys.argv[2:]

try:
    with open(settings_file, 'r') as f:
        settings = json.load(f)

    # Get hooks config
    hooks_config = settings.get('hooks', {})
    changes_made = False

    # Check all hook sections
    for hook_type in ['PreToolUse', 'PostToolUse', 'Stop']:
        hooks_section = hooks_config.get(hook_type, [])
        if not hooks_section:
            continue

        hooks_list = hooks_section[0].get('hooks', [])
        original_count = len(hooks_list)

        # Remove hooks that match removed files
        hooks_list[:] = [
            h for h in hooks_list
            if not any(removed in h.get('command', '') for removed in hooks_removed)
        ]

        new_count = len(hooks_list)
        if new_count < original_count:
            removed_count = original_count - new_count
            print(f"    Removed {removed_count} hook(s) from {hook_type}")
            changes_made = True

        hooks_section[0]['hooks'] = hooks_list

    # Write back if changes made
    if changes_made:
        with open(settings_file, 'w') as f:
            json.dump(settings, f, indent=2)
        print("  ✓ settings.json updated")
    else:
        print("  ✓ No hook registrations to remove")

except Exception as e:
    print(f"  ⚠️  Warning: Could not update settings.json: {e}", file=sys.stderr)

PYTHON_CLEANUP
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
    echo "    git clone -b v2.2.0 https://github.com/anthropics/claude-code-hooks-daemon.git .claude/hooks-daemon"
    echo "    cd .claude/hooks-daemon"
    echo "    ./scripts/install/install.bash"
    echo ""
    echo "  Or see: https://github.com/anthropics/claude-code-hooks-daemon"
    echo ""
fi

echo "✓ Skills, Agents & Hooks deployment complete"
echo ""
echo "Installed skills:"
ls -1 "$SKILLS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed agents:"
ls -1 "$AGENTS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed hooks:"
ls -1 "$HOOKS_TARGET" 2>/dev/null || echo "  (none)"
