set +e
phpStanExitCode=99
phpStanLogDir="$varDir/phpstan_logs"
phpStanLogFile="phpstan.log"
mkdir -p "$phpStanLogDir"

phpstanNoProgress=()
if [[ "true" == "$CI" ]]; then
  phpstanNoProgress+=(--no-progress)
fi

while ((phpStanExitCode > 0)); do
  # Run PHPStan with tee to capture output to both file and stdout
  phpNoXdebug -f "$pharDir"/phpstan.phar -- \
    analyse ${pathsToCheck[@]} \
    -c "$phpstanConfigPath" \
    ${phpstanNoProgress[@]:-} \
    2>&1 | tee "$phpStanLogDir/$phpStanLogFile"

  phpStanExitCode=${PIPESTATUS[0]}

  # Archive PHPStan log with timestamp - keep last 10 per pattern
  # Do this BEFORE tryAgainOrAbort so log is archived even on failure (in CI mode)
  # Uses shared archiveToolLog function from functions.inc.bash
  archiveToolLog "PHPStan" "$phpStanLogDir" "$phpStanLogFile" "$specifiedPath" "${pathsToCheck[@]}"

  #exit code 0 = fine, 1 = ran fine but found errors, else it means it crashed
  if ((phpStanExitCode > 1)); then
    printf "\n\n\nPHPStan Crashed!!....\n\nrunning again with debug mode:\nWhere ever it stops is probably a fatal PHP error\n\n"
    eval phpNoXdebug -f "$pharDir"/phpstan.phar -- analyse $pathsStringArray -c "$phpstanConfigPath" --debug -v
    exit 1
  fi
  if ((phpStanExitCode > 0)); then
    tryAgainOrAbort "PHPStan"
  fi
done

set -e
