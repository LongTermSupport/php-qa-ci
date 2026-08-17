# deploy-skills phase: OWNED artefacts (skills + agents).
#
# Sourced by scripts/deploy-skills.bash. Relies on variables and helpers the
# orchestrator has already established: SKILLS_SOURCE/SKILLS_TARGET,
# AGENTS_SOURCE/AGENTS_TARGET, install_owned_tree / install_owned_file from
# scripts/lib/consumer-write.inc.bash, and the manifest + resolver from
# scripts/lib/deploy-manifest.inc.bash. Skills and agents are php-qa-ci-OWNED:
# overwritten unconditionally (write-only-if-changed), never signature-checked.
#
# WHAT gets deployed is the manifest's decision, not a glob over whatever
# happens to be in .claude/ — see deploy-manifest.inc.bash for why that
# distinction matters (it is what stopped us destroying the hooks daemon's own
# deployed skill).
#
# The resolver's exit status is captured through a command substitution rather
# than a `< <(...)` process substitution, because the latter discards it — a
# manifest/package mismatch has to abort the deploy, not be silently skipped.

# Copy each manifest-listed skill.
skillPathList=""
if ! skillPathList="$(deploy_manifest_resolve "$SKILLS_SOURCE" "skill" "${PHPQACI_DEPLOY_SKILLS[@]}")"; then
    exit 1
fi

if [[ -n "$skillPathList" ]]; then
    while IFS= read -r skill_dir; do
        skill_name=$(basename "$skill_dir")
        echo "  Installing skill: $skill_name"

        # OWNED artefact: remove-then-copy, guarded against an empty target
        # (${VAR:?}) and printing an informational notice when the existing
        # tree differs. See scripts/lib/consumer-write.inc.bash.
        install_owned_tree "$skill_dir" "$SKILLS_TARGET/$skill_name" "skill '$skill_name'"

        # Make bundled scripts executable. compgen reports whether each glob
        # matches anything, so a chmod runs only when there is a real file to
        # chmod — this neither masks a genuine chmod failure nor treats an
        # empty match as an error.
        if [[ -d "$SKILLS_TARGET/$skill_name/scripts" ]]; then
            if compgen -G "$SKILLS_TARGET/$skill_name/scripts/*.py" > /dev/null; then
                chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.py
            fi
            if compgen -G "$SKILLS_TARGET/$skill_name/scripts/*.bash" > /dev/null; then
                chmod +x "$SKILLS_TARGET/$skill_name/scripts"/*.bash
            fi
        fi
    done <<< "$skillPathList"
fi

# Copy each manifest-listed agent (just markdown files).
agentPathList=""
if ! agentPathList="$(deploy_manifest_resolve "$AGENTS_SOURCE" "agent" "${PHPQACI_DEPLOY_AGENTS[@]}")"; then
    exit 1
fi

if [[ -n "$agentPathList" ]]; then
    while IFS= read -r agent_file; do
        agent_name=$(basename "$agent_file")
        echo "  Installing agent: $agent_name"
        install_owned_file "$agent_file" "$AGENTS_TARGET/$agent_name" "agent '$agent_name'"
    done <<< "$agentPathList"
fi
