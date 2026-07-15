
# shellcheck disable=SC2154 # binDir/pathsToCheck are set by bin/qa (setConfig, setPaths) before this fragment is sourced
if [[ -f "$binDir"/phploc ]]; then
  phpNoXdebug -f "$binDir"/phploc "${pathsToCheck[@]}"
fi
