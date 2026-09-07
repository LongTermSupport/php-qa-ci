#!/usr/bin/env bash
# Shared logic for php-qa-ci bin/ "redirect" stubs — tools that are fully
# managed by the QA pipeline and must not be invoked directly. Each stub
# sources this fragment and calls phpQaCiRedirectStub with a tool label and
# one or more usage lines, then exits 1.
#
# Consumed by: bin/composer-require-checker, bin/infection, bin/php-cs-fixer,
# bin/phpstan.
#
# Usage line convention: write the qa command placeholder as the literal
# token __QACMD__ (NOT $qaCmd, to avoid bash expanding it before it reaches
# this function); it is substituted with the resolved relative qa command.

# Claude hooks-daemon integration noise. The daemon's default extended PHP
# lint command is `phpstan analyse {file}`, which lands on the PHPStan stub
# and can never pass — false-failing every PHP edit in the session. php-qa-ci
# ships the stubs, so php-qa-ci owns telling the operator the exact
# `.claude/hooks-daemon.yaml` override that routes the lint through qa.
# Prints only when a daemon config exists AND lacks the override.
function phpQaCiHooksDaemonLintNotice() {
    local projectRoot="$1"
    local qaCmd="$2"
    local hooksDaemonConfig="$projectRoot/.claude/hooks-daemon.yaml"
    if [[ ! -f "$hooksDaemonConfig" ]]; then
        return 0
    fi
    if grep -qF 'qa -t phpstan -p {file}' "$hooksDaemonConfig"; then
        return 0
    fi
    cat <<NOTICE

------------------------------------------------------------------------------
HOOKS DAEMON DETECTED (.claude/hooks-daemon.yaml) WITHOUT THE QA LINT OVERRIDE
------------------------------------------------------------------------------
The Claude hooks-daemon's default extended PHP lint command is
'phpstan analyse {file}', which hits this stub and FALSE-FAILS EVERY PHP
Write/Edit in the session. Fix it by adding to .claude/hooks-daemon.yaml:

  handlers:
    post_tool_use:
      lint_on_edit:
        options:
          command_overrides:
            PHP:
              extended: "qa -t phpstan -p {file}"

then restart the daemon (/hooks-daemon restart). The daemon resolves the bare
'qa' against the project bin dirs; the pipeline accepts the absolute {file}
path ($qaCmd -t phpstan -p <file> is the per-file analysis entry point).
------------------------------------------------------------------------------
NOTICE
}

function phpQaCiRedirectStub() {
    local toolLabel="$1"
    shift

    # Find project root by walking up to composer.json
    local projectRoot="$COMPOSER_RUNTIME_BIN_DIR"
    while [[ ! -f "$projectRoot/composer.json" && "$projectRoot" != "/" ]]; do
        projectRoot="$(dirname "$projectRoot")"
    done
    # Make bin dir relative to project root
    local qaCmd="${COMPOSER_RUNTIME_BIN_DIR#"$projectRoot/"}/qa"

    echo "$toolLabel is managed by php-qa-ci. Run it via the QA pipeline:"
    echo ""
    local usageLine
    for usageLine in "$@"; do
        echo "${usageLine//__QACMD__/$qaCmd}"
    done

    if [[ "$toolLabel" == "PHPStan" ]]; then
        phpQaCiHooksDaemonLintNotice "$projectRoot" "$qaCmd"
    fi
    exit 1
}
