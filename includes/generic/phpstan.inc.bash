set +e
phpStanExitCode=99
phpStanLogDir="$varDir/phpstan_logs"
phpStanLogFile="phpstan.log"
mkdir -p "$phpStanLogDir"

# Limit parallel processing to use only half of available CPU threads
# to avoid overwhelming the system (consistent with Rector configuration)
cpuThreads=$(nproc 2>/dev/null || echo 4)
maxProcesses=$(( cpuThreads / 2 ))
if (( maxProcesses < 1 )); then
  maxProcesses=1
fi
phpStanParallelConfig="$phpStanLogDir/phpstan-parallel.neon"
cat > "$phpStanParallelConfig" << NEON
includes:
    - $phpstanConfigPath

parameters:
    parallel:
        maximumNumberOfProcesses: $maxProcesses
NEON
phpstanConfigPath="$phpStanParallelConfig"
echo "PHPStan: limiting to $maxProcesses parallel processes (50% of $cpuThreads cores)"

phpstanNoProgress=()
if [[ "true" == "$CI" ]]; then
  phpstanNoProgress+=(--no-progress)
fi

if [[ "1" == "${useJsonOutput:-0}" ]]; then
  # JSON mode: single run, no retry loop, structured output
  phpStanJsonFile="$phpStanLogDir/phpstan.json"

  phpNoXdebug -f "$pharDir"/phpstan.phar -- \
    analyse ${pathsToCheck[@]} \
    -c "$phpstanConfigPath" \
    --no-progress \
    --error-format=json \
    > "$phpStanJsonFile"

  phpStanExitCode=$?

  # Output JSON to fd 3 (original stdout, bypassing stderr redirect)
  cat "$phpStanJsonFile" >&3

  # Archive the JSON log
  archiveToolLog "PHPStan" "$phpStanLogDir" "phpstan.json" "$specifiedPath" "${pathsToCheck[@]}"

  if ((phpStanExitCode > 1)); then
    echo "PHPStan crashed (exit code: $phpStanExitCode)" >&2
    exit 1
  fi
  # Exit code 1 = found errors — expected for JSON consumption, don't retry
else
  # Text mode: original behavior with retry loop
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
fi

set -e
