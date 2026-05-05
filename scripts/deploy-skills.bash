#!/usr/bin/env bash

set -euo pipefail

QACI_PATH="${1:-}"
PROJECT_ROOT="${2:-}"

if [[ -z "$QACI_PATH" || -z "$PROJECT_ROOT" ]]; then
    echo "Usage: $0 <qaci-path> <project-root>"
    exit 1
fi

# Resolve to absolute paths so dirname works correctly (dirname "." = "." not parent)
QACI_PATH="$(realpath "$QACI_PATH")"
PROJECT_ROOT="$(realpath "$PROJECT_ROOT")"

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

# Detect daemon venv python3 for yaml operations (has pyyaml installed).
# Never falls back to system python3 — system Python won't have pyyaml, and
# silently skipping config validation is worse than a clear error.
# Supports monorepo: checks both project root and parent directory.
PYTHON3_YAML=""

_find_daemon_venv_python() {
    local daemon_root="$1"
    local untracked="$daemon_root/untracked"
    # Fingerprint-keyed venv (v3.9.0+): venv-<fingerprint>/bin/python3
    if [[ -d "$untracked" ]]; then
        for _venv_python in "$untracked"/venv-*/bin/python3; do
            if [[ -f "$_venv_python" ]]; then
                echo "$_venv_python"
                return 0
            fi
        done
    fi
    # Legacy path (pre-v3.9.0)
    local _legacy="$daemon_root/untracked/venv/bin/python3"
    if [[ -f "$_legacy" ]]; then
        echo "$_legacy"
        return 0
    fi
    return 1
}

_found=""
if _found=$(_find_daemon_venv_python "$PROJECT_ROOT/.claude/hooks-daemon"); then
    PYTHON3_YAML="$_found"
elif _found=$(_find_daemon_venv_python "$(dirname "$PROJECT_ROOT")/.claude/hooks-daemon"); then
    PYTHON3_YAML="$_found"
fi
unset _found

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

# ============================================================================
# Phase 3: Git Hooks Deployment
# ============================================================================
# Deploy git hooks (if not already present, or update if it's our hook)
GIT_HOOKS_SOURCE="$QACI_PATH/git-hooks"
GIT_HOOKS_TARGET="$PROJECT_ROOT/.git/hooks"

if [[ -d "$GIT_HOOKS_SOURCE" ]] && [[ -d "$GIT_HOOKS_TARGET" ]]; then
    echo ""
    echo "  Checking git hooks..."

    # Deploy pre-commit hook for checking vendor uncommitted changes
    PRE_COMMIT_SOURCE="$GIT_HOOKS_SOURCE/pre-commit-check-vendor-uncommitted"
    PRE_COMMIT_TARGET="$GIT_HOOKS_TARGET/pre-commit"

    if [[ -f "$PRE_COMMIT_SOURCE" ]]; then
        if [[ -f "$PRE_COMMIT_TARGET" ]]; then
            # Check if existing hook is our hook (by signature)
            if grep -q "PHP-QA-CI-HOOK-SIGNATURE: pre-commit-check-vendor-uncommitted" "$PRE_COMMIT_TARGET" 2>/dev/null; then
                echo "  Updating git pre-commit hook (php-qa-ci managed)..."
                cp "$PRE_COMMIT_SOURCE" "$PRE_COMMIT_TARGET"
                chmod +x "$PRE_COMMIT_TARGET"
                echo "  ✓ Git pre-commit hook updated: $PRE_COMMIT_TARGET"
            else
                echo "  ⚠️  Git pre-commit hook already exists (custom) - skipping deployment"
                echo "      Existing: $PRE_COMMIT_TARGET"
                echo "      To use php-qa-ci hook, backup existing and re-run deployment"
            fi
        else
            echo "  Installing git pre-commit hook..."
            cp "$PRE_COMMIT_SOURCE" "$PRE_COMMIT_TARGET"
            chmod +x "$PRE_COMMIT_TARGET"
            echo "  ✓ Git pre-commit hook installed: $PRE_COMMIT_TARGET"
        fi
    fi
fi

# ============================================================================
# Phase 4: hooks-daemon Config Enforcement
# ============================================================================
# Ensure projects using php-qa-ci have required daemon handlers configured

# Note: DAEMON_CONFIG already set earlier with monorepo detection

if [[ -f "$DAEMON_CONFIG" ]]; then
    echo ""
    echo "📋 hooks-daemon detected - enforcing required handler configuration..."

    if [[ -z "$PYTHON3_YAML" ]]; then
        echo "  ❌ ERROR: hooks daemon venv not found" >&2
        echo "  Cannot validate daemon config — hooks daemon is not installed or is outdated." >&2
        echo "  Please install or upgrade hooks daemon (v3.9.0+):" >&2
        echo "    curl -fsSL https://raw.githubusercontent.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon/main/scripts/upgrade.sh -o /tmp/upgrade.sh" >&2
        echo "    bash /tmp/upgrade.sh --project-root $PROJECT_ROOT" >&2
        exit 1
    fi

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
            rm -f "$hook_file"
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
    echo "    git clone -b v2.2.0 https://github.com/anthropics/claude-code-hooks-daemon.git .claude/hooks-daemon"
    echo "    cd .claude/hooks-daemon"
    echo "    ./scripts/install/install.bash"
    echo ""
    echo "  Or see: https://github.com/anthropics/claude-code-hooks-daemon"
    echo ""
fi

# ============================================================================
# Phase 6: PHPStan Custom Rule Infrastructure (Defence Before Fix)
# ============================================================================
# Scaffold the project for custom PHPStan rules so the "Defence Before Fix"
# workflow can function. See: https://ltscommerce.dev/articles/defence-before-fix-static-analysis
echo ""
echo "📐 Setting up PHPStan custom rule infrastructure..."

QACONFIG_DIR="$PROJECT_ROOT/qaConfig"
PHPSTAN_RULES_DIR="$QACONFIG_DIR/PHPStan/Rules"
SRC_PHPSTAN_DIR="$PROJECT_ROOT/src/PHPStan"
TEMPLATES_DIR="$QACI_PATH/templates"

# Create qaConfig/PHPStan/Rules/ directory
mkdir -p "$PHPSTAN_RULES_DIR"

# Create qaConfig/PHPStan/CLAUDE.md (only if not exists — project may customise)
if [[ ! -f "$QACONFIG_DIR/PHPStan/CLAUDE.md" ]]; then
    if [[ -f "$TEMPLATES_DIR/qaConfig-PHPStan-CLAUDE.md" ]]; then
        cp "$TEMPLATES_DIR/qaConfig-PHPStan-CLAUDE.md" \
           "$QACONFIG_DIR/PHPStan/CLAUDE.md"
        echo "  ✓ Created qaConfig/PHPStan/CLAUDE.md"
    fi
else
    echo "  ✓ qaConfig/PHPStan/CLAUDE.md already exists"
fi

# Create src/PHPStan/CLAUDE.md guardrail (only if not exists)
if [[ -d "$PROJECT_ROOT/src" ]]; then
    mkdir -p "$SRC_PHPSTAN_DIR"
    if [[ ! -f "$SRC_PHPSTAN_DIR/CLAUDE.md" ]]; then
        if [[ -f "$TEMPLATES_DIR/src-PHPStan-CLAUDE.md" ]]; then
            cp "$TEMPLATES_DIR/src-PHPStan-CLAUDE.md" \
               "$SRC_PHPSTAN_DIR/CLAUDE.md"
            echo "  ✓ Created src/PHPStan/CLAUDE.md guardrail"
        fi
    else
        echo "  ✓ src/PHPStan/CLAUDE.md guardrail already exists"
    fi
fi

# Ensure autoload-dev has QaConfig\ namespace mapping
if [[ -f "$PROJECT_ROOT/composer.json" ]]; then
    python3 - "$PROJECT_ROOT/composer.json" << 'PYTHON_AUTOLOAD'
import json
import sys

composer_file = sys.argv[1]
try:
    with open(composer_file, 'r') as f:
        composer = json.load(f)

    autoload_dev = composer.setdefault('autoload-dev', {})
    psr4 = autoload_dev.setdefault('psr-4', {})

    if 'QaConfig\\' not in psr4:
        psr4['QaConfig\\'] = ['qaConfig/']
        new_content = json.dumps(composer, indent=4) + '\n'
        with open(composer_file, 'w') as f:
            f.write(new_content)
        print("  ✓ Added QaConfig\\ autoload-dev PSR-4 entry")
        print("  ⚠ Run 'composer dump-autoload' to regenerate autoloader")
    else:
        print("  ✓ QaConfig\\ autoload-dev entry already present")

except Exception as e:
    print(f"  ⚠️  Could not update composer.json: {e}", file=sys.stderr)
PYTHON_AUTOLOAD
fi

echo "  ✓ PHPStan custom rule infrastructure ready"

# ============================================================================
# Summary
# ============================================================================

echo ""
echo "✓ Skills, Agents, Hooks & PHPStan infrastructure deployment complete"
echo ""
echo "Installed skills:"
ls -1 "$SKILLS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed agents:"
ls -1 "$AGENTS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed hooks:"
ls -1 "$HOOKS_TARGET" 2>/dev/null || echo "  (none)"
