# deploy-skills phase: final summary.
#
# Sourced by scripts/deploy-skills.bash. Relies on the orchestrator's
# SKILLS_TARGET, AGENTS_TARGET and HOOKS_TARGET. Prints what was installed. Each
# listing checks its directory exists first (rather than discarding an ls error)
# so a missing target reports "(none)" without masking any real failure.

# ============================================================================
# Summary
# ============================================================================

echo ""
echo "✓ Skills, Agents, Hooks & PHPStan infrastructure deployment complete"
echo ""
echo "Installed skills:"
if [[ -d "$SKILLS_TARGET" ]]; then
    ls -1 "$SKILLS_TARGET"
else
    echo "  (none)"
fi
echo ""
echo "Installed agents:"
if [[ -d "$AGENTS_TARGET" ]]; then
    ls -1 "$AGENTS_TARGET"
else
    echo "  (none)"
fi
echo ""
echo "Installed hooks:"
if [[ -d "$HOOKS_TARGET" ]]; then
    ls -1 "$HOOKS_TARGET"
else
    echo "  (none)"
fi
