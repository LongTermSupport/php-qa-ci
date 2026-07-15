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

# Ownership-model helpers (install_owned_file / install_owned_tree). Deployed
# skills/agents/hooks are php-qa-ci-OWNED and overwritten unconditionally with a
# single consistent mechanism — see scripts/lib/consumer-write.inc.bash.
# shellcheck source=scripts/lib/consumer-write.inc.bash
source "$QACI_PATH/scripts/lib/consumer-write.inc.bash"

# Minimum hooks-daemon version the daemon-config enforcement below targets
# (fingerprint-keyed venv + pyyaml). A single constant so the "not detected"
# install instructions cannot drift from what the YAML enforcement path requires
# (this previously pinned v2.2.0 while the enforcement assumed v3.9.0+).
readonly DAEMON_INSTALL_REF="v3.9.0"

# python3 is required for the settings.json / composer.json merges below (system
# python3, distinct from the daemon venv used for YAML). Check it ONCE, up front,
# BEFORE any mutation: a missing interpreter must fail cleanly here rather than
# abort a half-applied deploy at the first bare `python3` heredoc under `set -e`.
if ! command -v python3 > /dev/null; then
    echo "ERROR: python3 is required to deploy php-qa-ci skills/agents/hooks" >&2
    echo "       (used for settings.json and composer.json updates) but was not found on PATH." >&2
    echo "       Install python3 and re-run — no changes have been made yet." >&2
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
elif [[ "${PHP_QA_CI_ASSUME_HOOKS_DAEMON:-0}" == "1" ]]; then
    # Escape hatch: treat the project as daemon-managed even when the config
    # file isn't visible from this environment (e.g. composer running in a
    # container that doesn't mount .claude/). Presence of the daemon is a
    # project-level fact; it does not depend on this environment being able to
    # see the file or run the daemon's venv.
    DAEMON_DETECTED=true
    echo "  📋 hooks-daemon assumed present via PHP_QA_CI_ASSUME_HOOKS_DAEMON=1 (config file not visible here)"
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

# Consumer settings.json path — shared by the classic-hooks registration phase
# and the daemon-config teardown phase, so it is defined here once.
SETTINGS_FILE="$PROJECT_ROOT/.claude/settings.json"

# ---------------------------------------------------------------------------
# Phase modules. This script is a thin orchestrator: each sourced module owns
# one responsibility and runs in THIS shell (sharing the variables and helpers
# established above, and aborting the whole run on `exit` under `set -e`), so
# the observable behaviour — output lines, exit codes, files written — is
# identical to the previous monolith. Order is load-bearing and unchanged.
# ---------------------------------------------------------------------------

# shellcheck source=scripts/lib/deploy-owned-artefacts.inc.bash
source "$QACI_PATH/scripts/lib/deploy-owned-artefacts.inc.bash"

# shellcheck source=scripts/lib/deploy-classic-hooks.inc.bash
source "$QACI_PATH/scripts/lib/deploy-classic-hooks.inc.bash"

# shellcheck source=scripts/lib/deploy-git-hook.inc.bash
source "$QACI_PATH/scripts/lib/deploy-git-hook.inc.bash"

# shellcheck source=scripts/lib/deploy-daemon-config.inc.bash
source "$QACI_PATH/scripts/lib/deploy-daemon-config.inc.bash"

# shellcheck source=scripts/lib/deploy-phpstan-scaffold.inc.bash
source "$QACI_PATH/scripts/lib/deploy-phpstan-scaffold.inc.bash"

# shellcheck source=scripts/lib/deploy-claude-block.inc.bash
source "$QACI_PATH/scripts/lib/deploy-claude-block.inc.bash"

# shellcheck source=scripts/lib/deploy-summary.inc.bash
source "$QACI_PATH/scripts/lib/deploy-summary.inc.bash"
