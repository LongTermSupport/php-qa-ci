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

echo "Deploying Skills from: $SKILLS_SOURCE"
echo "                   to: $SKILLS_TARGET"
echo "Deploying Agents from: $AGENTS_SOURCE"
echo "                   to: $AGENTS_TARGET"
echo "Deploying Hooks from:  $HOOKS_SOURCE"
echo "                   to: $HOOKS_TARGET"

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

# Copy each hook (Python scripts)
if [[ -d "$HOOKS_SOURCE" ]]; then
    for hook_file in "$HOOKS_SOURCE"/*.py; do
        if [[ -f "$hook_file" ]]; then
            hook_name=$(basename "$hook_file")
            echo "  Installing hook: $hook_name"
            cp "$hook_file" "$HOOKS_TARGET/$hook_name"
            chmod +x "$HOOKS_TARGET/$hook_name"
        fi
    done
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

# Get existing hooks list
pre_tool_use = settings['hooks']['PreToolUse']
if not pre_tool_use:
    pre_tool_use = [{'hooks': []}]
    settings['hooks']['PreToolUse'] = pre_tool_use

hooks_list = pre_tool_use[0].get('hooks', [])

# Get existing hook commands
existing_commands = {h.get('command') for h in hooks_list if isinstance(h, dict)}

# Add new hooks if not already present
for hook_path in hooks_to_add:
    if hook_path not in existing_commands:
        hooks_list.append({
            'type': 'command',
            'command': hook_path,
            'timeout': 5
        })
        print(f"    Registered: {hook_path}")
    else:
        print(f"    Already registered: {hook_path}")

pre_tool_use[0]['hooks'] = hooks_list

# Write back
with open(settings_file, 'w') as f:
    json.dump(settings, f, indent=2)

PYTHON_SCRIPT
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
