#!/usr/bin/env bash
###############################################################################
# branchNamePolicy — enforce branch naming convention for PRs.
#
# Rule (canonical): A PR represents a new feature or a bug fix, never a single
# plan. Plans land as commits on feature/bugfix branches.
#
# Default allow-list (additive only via qaConfig/branchNamePolicy.yaml):
#   feature/  bugfix/  chore/  hotfix/
#
# Default exempt branches: only the repo's actual default branch, detected
# dynamically via `git symbolic-ref refs/remotes/origin/HEAD` (local) and
# `git ls-remote --symref origin HEAD` (queries upstream — works in CI).
# No hardcoded list of branch names — projects with non-standard names that
# detection cannot reach must configure via qaConfig/branchNamePolicy.yaml.
#
# Project override:
#   qaConfig/branchNamePolicy.yaml
#     extra_allowed_prefixes:
#       - release/
#     extra_exempt_branches:
#       - integration
#
# Exit codes (this sourced include):
#   0  PASS — branch is exempt or matches an allowed prefix
#   1  FAIL — branch is disallowed (extra-loud plan/* guidance when applicable)
#
# Note: this file is sourced by bin/qa's runTool, so it uses a function-wrapped
# pattern (_bnp_run) and exits via `return $rc` to play nicely with the runner.
###############################################################################

_bnp_run() {
    set -e
    set -u
    set -o pipefail

    # Locate project root (bin/qa sets $projectRoot; fall back to pwd for safety).
    local _bnp_projectRoot="${projectRoot:-$(pwd)}"

    # Stderr sink for probe-style git calls. We DO keep the contents so genuine
    # failures can be inspected — this is NOT silent error suppression; the log
    # is surfaced by the caller below when non-empty. Scope it under the
    # project's var dir (NOT shared /tmp, whose wildcard cleanup could clobber a
    # concurrent run's files). Not declared `local`, so the caller section at the
    # bottom of this file can surface and delete exactly this run's file.
    local _bnp_varDir="${varDir:-$_bnp_projectRoot/var/qa}"
    mkdir -p "$_bnp_varDir"
    _bnp_errLog="$(mktemp "$_bnp_varDir/branchNamePolicy.XXXXXX.err")"

    # Detect git repo. Skip cleanly if we are not inside one.
    local _bnp_inRepo=0
    if [[ -d "$_bnp_projectRoot/.git" ]]; then
        _bnp_inRepo=1
    elif (cd "$_bnp_projectRoot" && git rev-parse --is-inside-work-tree >/dev/null 2>>"$_bnp_errLog"); then
        _bnp_inRepo=1
    fi

    if (( _bnp_inRepo == 0 )); then
        echo "[branchNamePolicy] No git repository detected at $_bnp_projectRoot — skipping check."
        return 0
    fi

    # Current branch (HEAD). Use `if !` so failure does not abort under set -e.
    local _bnp_currentBranch=""
    if _bnp_currentBranch="$(cd "$_bnp_projectRoot" && git rev-parse --abbrev-ref HEAD 2>>"$_bnp_errLog")"; then
        :
    else
        _bnp_currentBranch=""
    fi

    if [[ -z "$_bnp_currentBranch" || "$_bnp_currentBranch" == "HEAD" ]]; then
        echo "[branchNamePolicy] Detached HEAD or no branch detected — skipping check."
        return 0
    fi

    echo "[branchNamePolicy] Current branch: $_bnp_currentBranch"

    # Detect repository default branch dynamically.
    # `git symbolic-ref refs/remotes/origin/HEAD` returns e.g. "refs/remotes/origin/main";
    # strip the prefix using bash parameter expansion to obtain the bare branch name.
    local _bnp_defaultBranchRef=""
    if _bnp_defaultBranchRef="$(cd "$_bnp_projectRoot" && git symbolic-ref refs/remotes/origin/HEAD 2>>"$_bnp_errLog")"; then
        :
    else
        _bnp_defaultBranchRef=""
    fi

    local _bnp_defaultBranch="${_bnp_defaultBranchRef#refs/remotes/origin/}"
    if [[ "$_bnp_defaultBranch" == "$_bnp_defaultBranchRef" ]]; then
        # Prefix didn't strip → empty/unrecognised value.
        _bnp_defaultBranch=""
    fi

    # If symbolic-ref returned nothing (common in CI — actions/checkout@v4
    # does not set origin/HEAD locally by default), ask the remote directly.
    # `git ls-remote --symref origin HEAD` prints e.g.
    #   ref: refs/heads/main\tHEAD
    #   <sha>\tHEAD
    # We parse the ref line and strip the refs/heads/ prefix.
    if [[ -z "$_bnp_defaultBranch" ]]; then
        local _bnp_lsremoteOutput=""
        if _bnp_lsremoteOutput="$(cd "$_bnp_projectRoot" && git ls-remote --symref origin HEAD 2>>"$_bnp_errLog")"; then
            local _bnp_lsremoteRef
            _bnp_lsremoteRef="$(printf '%s\n' "$_bnp_lsremoteOutput" | awk '$1 == "ref:" { print $2; exit }')"
            if [[ -n "$_bnp_lsremoteRef" ]]; then
                _bnp_defaultBranch="${_bnp_lsremoteRef#refs/heads/}"
                if [[ "$_bnp_defaultBranch" == "$_bnp_lsremoteRef" ]]; then
                    # Prefix didn't strip — unrecognised ref shape.
                    _bnp_defaultBranch=""
                fi
            fi
        fi
    fi

    if [[ -n "$_bnp_defaultBranch" ]]; then
        echo "[branchNamePolicy] Default branch detected: $_bnp_defaultBranch"
    fi

    # Default allowed prefixes.
    local -a _bnp_allowedPrefixes=(feature/ bugfix/ chore/ hotfix/)

    # Default exempt branches: only the detected default branch. NO hardcoded
    # list — that's overfitting. If detection failed entirely, the project
    # must configure exempt branches explicitly via qaConfig/branchNamePolicy.yaml.
    local -a _bnp_exemptBranches=()
    if [[ -n "$_bnp_defaultBranch" ]]; then
        _bnp_exemptBranches+=("$_bnp_defaultBranch")
    else
        echo "[branchNamePolicy] WARNING: could not detect default branch via git symbolic-ref or git ls-remote. If this branch should be exempt, add it to qaConfig/branchNamePolicy.yaml under extra_exempt_branches."
    fi

    # Project-local config (optional).
    local _bnp_configFile="$_bnp_projectRoot/qaConfig/branchNamePolicy.yaml"
    if [[ -f "$_bnp_configFile" ]]; then
        echo "[branchNamePolicy] Loading project overrides from $_bnp_configFile"
        # Minimal pure-bash YAML reader — supports only the two flat lists below.
        # Deliberately no external dependencies (no yq/jq/python).
        local _bnp_section=""
        local _bnp_line
        local _bnp_item
        while IFS= read -r _bnp_line || [[ -n "$_bnp_line" ]]; do
            # Strip trailing CR.
            _bnp_line="${_bnp_line%$'\r'}"
            # Skip comments and blank lines.
            [[ "$_bnp_line" =~ ^[[:space:]]*# ]] && continue
            [[ -z "${_bnp_line//[[:space:]]/}" ]] && continue
            if [[ "$_bnp_line" =~ ^extra_allowed_prefixes: ]]; then
                _bnp_section="prefixes"
                continue
            fi
            if [[ "$_bnp_line" =~ ^extra_exempt_branches: ]]; then
                _bnp_section="exempt"
                continue
            fi
            # Top-level non-section key → reset section.
            if [[ "$_bnp_line" =~ ^[A-Za-z_] ]]; then
                _bnp_section=""
                continue
            fi
            # List item: leading dash.
            if [[ "$_bnp_line" =~ ^[[:space:]]*-[[:space:]]*(.+)[[:space:]]*$ ]]; then
                _bnp_item="${BASH_REMATCH[1]}"
                # Strip surrounding quotes if any.
                _bnp_item="${_bnp_item%\"}"
                _bnp_item="${_bnp_item#\"}"
                _bnp_item="${_bnp_item%\'}"
                _bnp_item="${_bnp_item#\'}"
                # Strip trailing whitespace.
                _bnp_item="${_bnp_item%"${_bnp_item##*[![:space:]]}"}"
                case "$_bnp_section" in
                    prefixes) _bnp_allowedPrefixes+=("$_bnp_item") ;;
                    exempt)   _bnp_exemptBranches+=("$_bnp_item") ;;
                esac
            fi
        done < "$_bnp_configFile"
    fi

    # Exempt branch check.
    local _bnp_exempt
    for _bnp_exempt in "${_bnp_exemptBranches[@]}"; do
        if [[ "$_bnp_currentBranch" == "$_bnp_exempt" ]]; then
            echo "[branchNamePolicy] PASS — branch '$_bnp_currentBranch' is exempt (default/protected)."
            return 0
        fi
    done

    # Allowed prefix check.
    local _bnp_prefix
    for _bnp_prefix in "${_bnp_allowedPrefixes[@]}"; do
        if [[ "$_bnp_currentBranch" == "$_bnp_prefix"* ]]; then
            echo "[branchNamePolicy] PASS — branch '$_bnp_currentBranch' matches allowed prefix '$_bnp_prefix'."
            return 0
        fi
    done

    # FAIL path — branch is not allowed.
    local _bnp_isPlanBranch=0
    if [[ "$_bnp_currentBranch" == plan/* ]]; then
        _bnp_isPlanBranch=1
    fi

    local _bnp_allowedList=""
    local _bnp_p
    for _bnp_p in "${_bnp_allowedPrefixes[@]}"; do
        _bnp_allowedList+="    - $_bnp_p"$'\n'
    done

    cat >&2 <<EOF

==============================================================================
branchNamePolicy: DISALLOWED BRANCH
==============================================================================

Current branch: $_bnp_currentBranch

Allowed prefixes:
$_bnp_allowedList
Rule:
    A PR represents a new feature or a bug fix, never a single plan.
    Plans land as commits on feature/bugfix branches.

Full convention:
    vendor/lts/php-qa-ci/CLAUDE/branch-policy.md

To extend the allow-list for this project (additive only), create:
    qaConfig/branchNamePolicy.yaml
With contents like:
    extra_allowed_prefixes:
      - release/
    extra_exempt_branches:
      - integration

EOF

    if (( _bnp_isPlanBranch == 1 )); then
        cat >&2 <<EOF
==============================================================================
‼  PLAN BRANCH DETECTED — STOP AND READ
==============================================================================

You are on a plan/* branch. Plans are atomic pieces of work and MUST NOT be
the unit of a PR. A feature/bugfix branch may carry zero or more plans, each
landing as one (or more) commit(s).

What to do:
  1. git checkout feature/your-feature-name   # or bugfix/, chore/, hotfix/
     (create the feature branch with: git checkout -b feature/your-feature-name)
  2. Bring your plan commits onto the feature branch (cherry-pick or merge).
  3. Open the PR from the feature branch — never from plan/*.

See vendor/lts/php-qa-ci/CLAUDE/branch-policy.md for the worked example.

==============================================================================
EOF
    fi

    return 1
}

_bnp_run
_bnp_rc=$?
unset -f _bnp_run
# Surface any git-probe diagnostics captured during the run (genuine git
# failures land here), then remove ONLY this run's capture file — never a shared
# /tmp glob that could delete another process's files.
if [[ -n "${_bnp_errLog:-}" && -s "$_bnp_errLog" ]]; then
    echo "[branchNamePolicy] git probe diagnostics (non-fatal unless the check itself failed):" >&2
    cat "$_bnp_errLog" >&2
fi
if [[ -n "${_bnp_errLog:-}" ]]; then
    rm -f "$_bnp_errLog"
fi
unset _bnp_errLog
if (( _bnp_rc == 0 )); then
    unset _bnp_rc
else
    unset _bnp_rc
    exit 1
fi
