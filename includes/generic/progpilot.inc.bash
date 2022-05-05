if (( $phpMajorVersion > 7 )); then
  echo " ** Prog pilot is not compatible with PHP versions greater than 7 ** "
else
  if [[ ! -f "$pharDir"/progpilot.phar ]]; then
    bash "$pharDir"/install.bash
  fi
  set +e
  progpilotExitCode=99
  progpilotMemoryLimit=${progpilotMemoryLimit:-256M}
  while ((progpilotExitCode > 0)); do
    phpNoXdebug -d memory_limit=${progpilotMemoryLimit} -f "$pharDir"/progpilot.phar -- --configuration "$progpilotConfigPath" ${pathsToCheck[@]}
    progpilotExitCode=$?
    if ((progpilotExitCode > 0)); then
      tryAgainOrAbort "progpilot"
    fi
  done
  set -e
fi
