# Deploy-time hooks-daemon lint-override check.
#
# The Claude hooks-daemon's default extended PHP lint command is
# `phpstan analyse {file}`. In a php-qa-ci project that lands on the
# always-exit-1 redirect stub (bin/phpstan), so every PHP Write/Edit in a
# Claude session false-fails until the daemon config routes the lint through
# the QA pipeline. php-qa-ci ships the stubs, so php-qa-ci owns surfacing the
# fix at deploy time.
#
# This check only INSTRUCTS — it prints the exact YAML for a human or agent
# to add. It never edits the daemon YAML itself: comment preservation and
# structural merge belong to whoever applies the change, not a deploy script.
# Advisory by design: always returns 0 so it can never fail a deployment.
#
# Usage: phpQaCiDaemonLintOverrideCheck <path-to-hooks-daemon.yaml>

# True (0) when the daemon config exists but lacks the qa lint override —
# i.e. the misconfiguration the notices exist for.
function phpQaCiDaemonLintOverrideMissing() {
    local daemonConfig="$1"
    [[ -f "$daemonConfig" ]] && ! grep -qF 'qa -t phpstan -p {file}' "$daemonConfig"
}

# Filter the <phpqaci> CLAUDE.md block template: the lint-integration section
# (delimited by the daemon-lint-notice:start/end marker comments) is kept ONLY
# while the misconfiguration exists — once the override is configured (or no
# daemon is present) the section is stripped, saving every future session the
# tokens. Marker lines themselves never ship either way.
#
# Usage: phpQaCiFilterClaudeBlockTemplate <template> <daemon-config> <out-file>
function phpQaCiFilterClaudeBlockTemplate() {
    local template="$1"
    local daemonConfig="$2"
    local outFile="$3"
    if phpQaCiDaemonLintOverrideMissing "$daemonConfig"; then
        grep -vF 'daemon-lint-notice' "$template" > "$outFile"
        return 0
    fi
    awk '
        /<!-- daemon-lint-notice:start -->/ { skipping = 1; next }
        /<!-- daemon-lint-notice:end -->/   { skipping = 0; next }
        skipping != 1 { print }
    ' "$template" > "$outFile"
}

function phpQaCiDaemonLintOverrideCheck() {
    local daemonConfig="$1"
    if [[ ! -f "$daemonConfig" ]]; then
        return 0
    fi
    if grep -qF 'qa -t phpstan -p {file}' "$daemonConfig"; then
        echo "  ✓ hooks-daemon PHP extended-lint override is configured (qa -t phpstan)"
        return 0
    fi
    cat <<NOTICE

  ⚠️  ACTION REQUIRED: hooks-daemon is missing the php-qa-ci lint override
  ------------------------------------------------------------------------
  The daemon's default extended PHP lint command is 'phpstan analyse {file}',
  which hits php-qa-ci's redirect stub and FALSE-FAILS EVERY PHP Write/Edit
  in a Claude session. Add this to $daemonConfig
  (merge into the existing handlers tree, do not duplicate keys):

    handlers:
      post_tool_use:
        lint_on_edit:
          options:
            command_overrides:
              PHP:
                extended: "qa -t phpstan -p {file}"

  Then restart the daemon (/hooks-daemon restart). The daemon resolves the
  bare 'qa' against the project bin dirs, and php-qa-ci accepts the absolute
  {file} path for per-file analysis.
  ------------------------------------------------------------------------
NOTICE
    return 0
}
