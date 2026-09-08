# phpLint — fast parallel PHP syntax check (php -l) over pathsToCheck via
# bin/parallel-lint, excluding every entry in pathsToIgnore.

# shellcheck disable=SC2154 # pathsToIgnore/binDir/pathsToCheck/projectRoot are set by bin/qa (setPaths, setConfig) before this fragment is sourced
pathsToIgnorePrefixed=()
for ignoreFile in "${pathsToIgnore[@]}"; do
    pathsToIgnorePrefixed+=( --exclude "$projectRoot/$ignoreFile")
done

qaSimpleTool "PHP Lint" phpNoXdebug -f "$binDir"/parallel-lint -- \
    "${pathsToIgnorePrefixed[@]}" \
    "${pathsToCheck[@]}"
