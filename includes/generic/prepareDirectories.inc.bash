mkdir -p "$varDir";
echo '
*
!.gitignore
' > "$varDir/.gitignore"

mkdir -p "$cacheDir";
echo '
*
!.gitignore
' > "$cacheDir/.gitignore"

# Ensure the consuming project's root .gitignore excludes QA runtime caches
# wherever they might appear in the tree. We manage a marked block so the
# edit is idempotent — if the markers exist we leave the block alone, if not
# we append it. This protects projects against misconfigured tools writing
# cache dirs into tracked folders (e.g. PHPUnit's default .phpunit.cache
# ending up next to phpunit.xml in qaConfig/).
ensureProjectGitignoreGuards() {
    local rootGitignore="$projectRoot/.gitignore"
    local markerStart="# BEGIN php-qa-ci managed QA runtime excludes"

    if [[ -f "$rootGitignore" ]] && grep -qF "$markerStart" "$rootGitignore"; then
        return 0
    fi

    if [[ ! -f "$rootGitignore" ]]; then
        touch "$rootGitignore"
    fi

    # Ensure there's a blank line before the managed block for readability,
    # but only if the file is non-empty and doesn't already end with one.
    if [[ -s "$rootGitignore" ]] && [[ -n "$(tail -c1 "$rootGitignore")" ]]; then
        printf '\n' >> "$rootGitignore"
    fi

    cat >> "$rootGitignore" << 'GITIGNORE_EOF'

# BEGIN php-qa-ci managed QA runtime excludes — do not edit
# These patterns ensure QA tool runtime caches are never committed,
# regardless of where they happen to appear in the project tree.
# Managed by vendor/lts/php-qa-ci/includes/generic/prepareDirectories.inc.bash
**/.phpunit.cache/
**/.qa-lock/
**/.php-cs-fixer.cache
**/.php_cs.cache
**/.rector.cache/
**/phpstan-result.json
# END php-qa-ci managed QA runtime excludes
GITIGNORE_EOF
}

ensureProjectGitignoreGuards
