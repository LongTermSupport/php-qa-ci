# deploy-skills phase: OWNED artefacts (skills + agents).
#
# Sourced by scripts/deploy-skills.bash. Relies on variables and helpers the
# orchestrator has already established: SKILLS_SOURCE/SKILLS_TARGET,
# AGENTS_SOURCE/AGENTS_TARGET, and install_owned_tree / install_owned_file from
# scripts/lib/consumer-write.inc.bash. Skills and agents are php-qa-ci-OWNED:
# overwritten unconditionally (write-only-if-changed), never signature-checked.

# Copy each skill
if [[ -d "$SKILLS_SOURCE" ]]; then
    for skill_dir in "$SKILLS_SOURCE"/*; do
        if [[ -d "$skill_dir" ]]; then
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
        fi
    done
fi

# Copy each agent (just markdown files)
if [[ -d "$AGENTS_SOURCE" ]]; then
    for agent_file in "$AGENTS_SOURCE"/*.md; do
        if [[ -f "$agent_file" ]]; then
            agent_name=$(basename "$agent_file")
            echo "  Installing agent: $agent_name"
            install_owned_file "$agent_file" "$AGENTS_TARGET/$agent_name" "agent '$agent_name'"
        fi
    done
fi
