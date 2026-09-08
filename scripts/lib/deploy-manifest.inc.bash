# DEPLOY MANIFEST — the single source of truth for what php-qa-ci deploys into
# a consuming project.
#
# WHY AN EXPLICIT LIST RATHER THAN A GLOB
# ---------------------------------------
# A glob over `$SOURCE/*` would make "what we ship" whatever happens to be
# sitting in .claude/ at package time. That is not a decision, it is an
# accident, and it fails in both directions:
#
#   - A directory that is NOT ours to deploy gets deployed anyway. The
#     hooks-daemon skill is the worked example: the hooks daemon deploys that
#     skill itself, from its own source tree, on every install/upgrade. Both
#     packages therefore wrote the same path and the last writer won. Because
#     install_owned_tree is remove-then-copy, our older snapshot did not merely
#     overwrite the daemon's copy — it DELETED the files the daemon ships and we
#     do not (a consumer on daemon v3.53.1 lost the skill's plan-qa.md to a
#     plain `composer install`).
#
#   - A new artefact starts shipping to every consumer the moment someone adds a
#     directory, with no review of that decision.
#
# So the list below is deliberate. Adding an artefact to the repository does NOT
# ship it; adding it HERE does. deploy_manifest_resolve() (see below) enforces
# the contract in both directions: a listed artefact that is missing from the
# source tree is a hard error, and a source artefact that is not listed is
# reported so the omission is a conscious choice rather than a silent drop.
#
# This file is sourced, not executed. It defines data + one helper.

# Include guard: the arrays below are readonly, so a second source would abort
# the run with "readonly variable" under errexit.
if [[ -n "${PHPQACI_DEPLOY_MANIFEST_LOADED:-}" ]]; then
    return 0
fi
readonly PHPQACI_DEPLOY_MANIFEST_LOADED=1

# Skills deployed into <consumer>/.claude/skills/<name>/.
#
# NOT LISTED, deliberately:
#   hooks-daemon — owned and deployed by the hooks daemon itself. See the
#   remove-then-copy hazard above. Projects install the daemon via its own
#   installer (the script prints the instructions when it is absent), so
#   shipping a competing snapshot buys nothing and actively destroys state.
# Each entry is a DIRECTORY NAME. The whole directory is copied recursively by
# install_owned_tree, so the manifest never enumerates a skill's contents — add
# a file to a listed skill and it ships automatically.
#
# shellcheck disable=SC2034 # consumed by deploy-owned-artefacts.inc.bash, a
#   sibling fragment sourced by deploy-skills.bash — the sourced-fragment
#   architecture this repo's shellcheck sweep documents.
readonly -a PHPQACI_DEPLOY_SKILLS=(
    branch-policy
    defence-before-fix
    gh-links
    phpstan-fixer
    phpstan-runner
    phpunit-fixer
    phpunit-runner
    qa
    qa-tool-runner
)

# Agents deployed into <consumer>/.claude/agents/.
# shellcheck disable=SC2034 # consumed by deploy-owned-artefacts.inc.bash (sourced fragment).
readonly -a PHPQACI_DEPLOY_AGENTS=(
    php-qa-ci_docs-conflict-checker.md
    php-qa-ci_full-pipeline-runner.md
    php-qa-ci_phpstan-fixer.md
    php-qa-ci_phpstan-rule-creator.md
    php-qa-ci_phpstan-runner.md
    php-qa-ci_phpunit-fixer.md
    php-qa-ci_phpunit-runner.md
    php-qa-ci_qa-fix-auditor.md
    php-qa-ci_qa-tool-runner.md
)

# Classic Claude Code hooks deployed into <consumer>/.claude/hooks/.
# Only deployed when the hooks daemon is ABSENT — when it is present the daemon
# supersedes these and deploy-classic-hooks.inc.bash removes them instead.
# shellcheck disable=SC2034 # consumed by deploy-classic-hooks.inc.bash (sourced fragment).
readonly -a PHPQACI_DEPLOY_HOOKS=(
    php-qa-ci__auto-continue.py
    php-qa-ci__block-plan-time-estimates.py
    php-qa-ci__discourage-git-stash.py
    php-qa-ci__enforce-markdown-organization.py
    php-qa-ci__prevent-destructive-git.py
    php-qa-ci__validate-claude-readme-content.py
)

# deploy_manifest_resolve <source_dir> <kind> <artefact>...
#
# Verifies the manifest against the source tree and prints one absolute path per
# listed artefact on stdout (the caller iterates that). Enforces both directions:
#
#   - LISTED BUT ABSENT is fatal. The manifest is the shipping contract; a
#     missing entry means the package is broken or an artefact was renamed
#     without updating this file, and silently deploying less than promised is
#     how a guardrail goes missing unnoticed.
#   - PRESENT BUT UNLISTED is reported (non-fatal) on stderr, so a newly added
#     artefact is noticed and shipped by an explicit edit here rather than by
#     accident. Non-fatal because a deliberate exclusion (hooks-daemon) must not
#     break every consumer's composer install.
deploy_manifest_resolve() {
    local source_dir="$1" kind="$2"
    shift 2
    local -a listed=("$@")

    if [[ ! -d "$source_dir" ]]; then
        echo "ERROR: php-qa-ci $kind source directory is missing: $source_dir" >&2
        return 1
    fi

    local artefact missing=0
    for artefact in "${listed[@]}"; do
        if [[ ! -e "$source_dir/$artefact" ]]; then
            echo "ERROR: manifest lists $kind '$artefact' but it is not in $source_dir" >&2
            missing=1
            continue
        fi
        printf '%s\n' "$source_dir/$artefact"
    done

    if ((missing == 1)); then
        echo "ERROR: php-qa-ci deploy manifest is out of step with the package contents (see above)." >&2
        echo "       Fix scripts/lib/deploy-manifest.inc.bash, or restore the missing $kind." >&2
        return 1
    fi

    # Drift report: anything in the source tree we are NOT shipping.
    local entry base found
    for entry in "$source_dir"/*; do
        [[ -e "$entry" ]] || continue
        base="$(basename "$entry")"
        found=0
        for artefact in "${listed[@]}"; do
            if [[ "$base" == "$artefact" ]]; then
                found=1
                break
            fi
        done
        if ((found == 0)); then
            echo "  NOTE: $kind '$base' is present but NOT in the deploy manifest — not deployed." >&2
        fi
    done

    return 0
}
