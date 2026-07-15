# deploy-skills phase: git pre-commit hook (SHARED / signature exception).
#
# Sourced by scripts/deploy-skills.bash. Relies on the orchestrator's QACI_PATH
# and PROJECT_ROOT plus install_signed from scripts/lib/consumer-write.inc.bash.
# .git/hooks/pre-commit is a singleton path shared with husky/lefthook/etc, so
# it is NOT owned: install_signed overwrites only when the target is absent or
# already carries our marker, and leaves a foreign hook intact with a warning.

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
        # .git/hooks/pre-commit is a SINGLETON path git shares with husky/
        # lefthook/hand-rolled hooks — the SHARED/signature exception in
        # scripts/lib/consumer-write.inc.bash. install_signed overwrites only
        # when the target is absent or carries our marker; a foreign hook gets
        # a loud warning and is left intact (never fails composer install).
        install_signed "$PRE_COMMIT_SOURCE" "$PRE_COMMIT_TARGET" "PHP-QA-CI-HOOK-SIGNATURE" "git pre-commit hook"
        # Only ours needs the exec bit + success line; a foreign hook was
        # deliberately left alone (install_signed already warned).
        if [[ -f "$PRE_COMMIT_TARGET" ]] && grep -q 'PHP-QA-CI-HOOK-SIGNATURE' "$PRE_COMMIT_TARGET"; then
            chmod +x "$PRE_COMMIT_TARGET"
            echo "  ✓ Git pre-commit hook installed: $PRE_COMMIT_TARGET"
        fi
    fi
fi
