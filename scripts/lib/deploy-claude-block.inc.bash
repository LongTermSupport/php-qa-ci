# deploy-skills phase: project root CLAUDE.md <phpqaci> block.
#
# Sourced by scripts/deploy-skills.bash. Relies on the orchestrator's QACI_PATH
# and PROJECT_ROOT. Injects (or idempotently replaces) the auto-managed
# <phpqaci> block via write-claude-block.bash (SHARED / delimited) — the block
# documents the branchNamePolicy convention. A writer failure is non-fatal.

# ============================================================================
# Phase 7: Project root CLAUDE.md <phpqaci> block
# ============================================================================
# Inject (or idempotently replace) the auto-managed <phpqaci> block in the
# project's root CLAUDE.md. Documents the branchNamePolicy convention.
echo ""
echo "📝 Updating project CLAUDE.md <phpqaci> block..."
PHPQACI_BLOCK_TEMPLATE="$QACI_PATH/templates/root-CLAUDE-phpqaci-block.md.template"
PHPQACI_BLOCK_TARGET="$PROJECT_ROOT/CLAUDE.md"
PHPQACI_BLOCK_WRITER="$QACI_PATH/scripts/write-claude-block.bash"

if [[ -f "$PHPQACI_BLOCK_TEMPLATE" && -f "$PHPQACI_BLOCK_WRITER" ]]; then
    # Conditional content: the hooks-daemon lint-integration section is only
    # worth its tokens while the daemon config actually lacks the override —
    # filter the template accordingly (see daemon-lint-override-check.inc.bash).
    # shellcheck source=scripts/lib/daemon-lint-override-check.inc.bash
    source "$QACI_PATH/scripts/lib/daemon-lint-override-check.inc.bash"
    PHPQACI_BLOCK_FILTERED="$(mktemp)"
    phpQaCiFilterClaudeBlockTemplate \
        "$PHPQACI_BLOCK_TEMPLATE" \
        "${DAEMON_CONFIG:-$PROJECT_ROOT/.claude/hooks-daemon.yaml}" \
        "$PHPQACI_BLOCK_FILTERED"
    PHPQACI_BLOCK_TEMPLATE="$PHPQACI_BLOCK_FILTERED"

    if bash "$PHPQACI_BLOCK_WRITER" "$PHPQACI_BLOCK_TEMPLATE" "$PHPQACI_BLOCK_TARGET"; then
        echo "  ✓ <phpqaci> block in CLAUDE.md is current"
    else
        BLOCK_RC=$?
        echo "  ⚠️  write-claude-block.bash failed (exit $BLOCK_RC) — see message above" >&2
        # Non-fatal: composer install/update should not break on this.
    fi
    rm -f "$PHPQACI_BLOCK_FILTERED"
else
    echo "  ⚠️  Skipping CLAUDE.md block update — template or writer missing"
fi
