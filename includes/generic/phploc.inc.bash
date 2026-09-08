# phploc — informational code-size/complexity statistics, printed after the
# pipeline has passed. Never fails the run; skipped when phploc is not installed.

# shellcheck disable=SC2154 # binDir/pathsToCheck are set by bin/qa (setConfig, setPaths) before this fragment is sourced
if [[ -f "$binDir"/phploc ]]; then
  phpNoXdebug -f "$binDir"/phploc "${pathsToCheck[@]}"
fi
