# shellcheck disable=SC2154 # pathsToIgnore/binDir/pathsToCheck are set by bin/qa (setPaths, setConfig) before this fragment is sourced
pathsToIgnorePrefixed=()

for ignoreFile in "${pathsToIgnore[@]}"
do
    pathsToIgnorePrefixed+=( --exclude "$projectRoot/$ignoreFile")
done

# Retry loop via the shared driver (M-010). The driver's if-condition capture
# replaces the errexit-toggling the loop used to do, with identical behaviour
# (and correctly quoted array expansion, closing the SC2068 hazard M-025 noted).
qaSimpleTool "PHP Lint" phpNoXdebug -f "$binDir"/parallel-lint -- \
    "${pathsToIgnorePrefixed[@]}" \
    "${pathsToCheck[@]}"