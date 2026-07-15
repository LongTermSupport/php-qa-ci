set +e
phpStanExitCode=99
# shellcheck disable=SC2154 # varDir is set by bin/qa (setConfig) before this fragment is sourced
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

  # shellcheck disable=SC2154 # pharDir/pathsToCheck are set by bin/qa (setConfig, setPaths)
  phpNoXdebug -f "$pharDir"/phpstan.phar -- \
    analyse "${pathsToCheck[@]}" \
    -c "$phpstanConfigPath" \
    --no-progress \
    --error-format=json \
    > "$phpStanJsonFile"

  phpStanExitCode=$?

  # Output JSON to fd 3 (original stdout, bypassing stderr redirect)
  cat "$phpStanJsonFile" >&3

  # Archive the JSON log
  # shellcheck disable=SC2154 # specifiedPath/pathsToCheck are set by bin/qa (options.inc.bash, setPaths)
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
      analyse "${pathsToCheck[@]}" \
      -c "$phpstanConfigPath" \
      "${phpstanNoProgress[@]}" \
      2>&1 | tee "$phpStanLogDir/$phpStanLogFile"

    phpStanExitCode=${PIPESTATUS[0]}

    # Archive PHPStan log with timestamp - keep last 10 per pattern
    # Do this BEFORE tryAgainOrAbort so log is archived even on failure (in CI mode)
    # Uses shared archiveToolLog function from functions.inc.bash
    archiveToolLog "PHPStan" "$phpStanLogDir" "$phpStanLogFile" "$specifiedPath" "${pathsToCheck[@]}"

    #exit code 0 = fine, 1 = ran fine but found errors, else it means it crashed
    if ((phpStanExitCode > 1)); then
      printf "\n\n\nPHPStan Crashed!!....\n\nrunning again with debug mode:\nWhere ever it stops is probably a fatal PHP error\n\n"
      phpNoXdebug -f "$pharDir"/phpstan.phar -- \
        analyse "${pathsToCheck[@]}" -c "$phpstanConfigPath" --debug -v
      exit 1
    fi
    if ((phpStanExitCode > 0)); then
      # Educational note for the "tautology from stronger types" identifiers. These
      # fire when PHPStan can already PROVE the check from the declared types
      # (alreadyNarrowedType / alwaysTrue / alwaysFalse / impossibleCheck). In TEST
      # code this very often means a recent type-safety improvement made an
      # assertion redundant — the production types now guarantee exactly what the
      # test was asserting. We only print this when such an identifier is actually
      # present, so it stays quiet for ordinary errors.
      if grep -qE 'alreadyNarrowedType|alwaysTrue|alwaysFalse|impossibleCheck' "$phpStanLogDir/$phpStanLogFile"; then
        printf '\n%s\n' \
"NOTE — possible tautology from stronger types
---------------------------------------------
One or more errors above (alreadyNarrowedType / alwaysTrue / alwaysFalse /
impossibleCheck) report a check PHPStan can already prove from the types alone.

In TEST code this is usually a GOOD sign: a type-safety improvement has made the
assertion tautological — the production types now guarantee what the test pinned.
When that is the case, DELETING the now-redundant assertion (or the whole
tautological test) is the CORRECT fix, not a workaround. The type system has
absorbed the guarantee the test used to provide; that is the goal, not a loss of
coverage. Keep the tests that still exercise real runtime behaviour.

In PRODUCTION code the same identifiers usually flag genuinely dead or redundant
logic — simplify it (remove the impossible branch / redundant comparison).

Either way, fix the CAUSE. Do NOT silence it with treatPhpDocTypesAsCertain:false,
a baseline entry, or a @phpstan-ignore comment."
      fi
      tryAgainOrAbort "PHPStan"
    fi
  done
fi

set -e
