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

echo "Deploying Skills from: $SKILLS_SOURCE"
echo "                   to: $SKILLS_TARGET"
echo "Deploying Agents from: $AGENTS_SOURCE"
echo "                   to: $AGENTS_TARGET"

# Create .claude directories
mkdir -p "$SKILLS_TARGET"
mkdir -p "$AGENTS_TARGET"

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

# Ensure .gitignore excludes .claude/
GITIGNORE="$PROJECT_ROOT/.gitignore"
if [[ -f "$GITIGNORE" ]] && ! grep -q "^\.claude/$" "$GITIGNORE"; then
    echo "" >> "$GITIGNORE"
    echo "# Claude Code personal configuration" >> "$GITIGNORE"
    echo ".claude/" >> "$GITIGNORE"
    echo "  Added .claude/ to .gitignore"
fi

echo "✓ Skills & Agents deployment complete"
echo ""
echo "Installed skills:"
ls -1 "$SKILLS_TARGET" 2>/dev/null || echo "  (none)"
echo ""
echo "Installed agents:"
ls -1 "$AGENTS_TARGET" 2>/dev/null || echo "  (none)"
