# First we run the Safe Rectors to implement safe versions of functions.

rectorVerbosity="-vv"
rectorIgnorePaths="";
if [[ "placeholder-ignore-item" != "${pathsToIgnore[*]}" ]]; then
  rectorIgnorePaths="${pathsToIgnore[*]}"
fi


rectorSafeExitCode=99
while ((rectorSafeExitCode > 1)); do
  set +e
  echo "Running 'Safe' Rector to convert to safe versions of functions"
  rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$binDir"/rector -- $rectorVerbosity process ${pathsToCheck[@]} \
    --config "$(configPath rector-safe.php)" \
    --clear-cache
  rectorSafeExitCode=$?
  set -e
  if ((rectorSafeExitCode > 0)); then
    tryAgainOrAbort "Rector 'Safe'"
  fi
done



rectorPhpUnitExitCode=99
while ((rectorPhpUnitExitCode > 1)); do
  set +e
  echo "Running PHPUnit Rector on $testsDir"
  rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$binDir"/rector -- $rectorVerbosity process $testsDir \
    --config "$(configPath rector-phpunit.php)" \
    --clear-cache
  rectorPhpUnitExitCode=$?
  set -e
  if ((rectorPhpUnitExitCode > 0)); then
    tryAgainOrAbort "Rector 'PHPUnit'"
  fi
done

# Then we check for project specific Rectors.
for rectorConfig in "$projectRoot/rector.php" "$projectRoot/qaConfig/rector.php";  do
  if [[ -f $rectorConfig ]]; then
    rectorExitCode=99
    while ((rectorExitCode > 1)); do
      set +e
      echo "Running Project Specific Rector as configured in $rectorConfig"
      phpNoXdebug -f "$binDir"/rector -- $rectorVerbosity process ${pathsToCheck[@]} \
        --config "$rectorConfig" \
        --clear-cache
      rectorExitCode=$?
      set -e
      if ((rectorExitCode > 0)); then
        tryAgainOrAbort "Rector Project Specific"
      fi
    done
  fi
done
#if [[ -f $projectRoot/rector.php ]]; then
#  echo "Running Project Specific Rector as configured in $projectRoot/rector.php"
#  if [[ -f $projectRoot/bin/console ]]; then
#    (cd $projectRoot && APP_ENV=dev phpNoXdebug -f ./bin/console -- cache:clear)
#  fi
#  rectorExitCode=99
#  while ((rectorExitCode > 1)); do
#    set +e
#    echo "Running Project Specific Rector"
#    phpNoXdebug -f "$binDir"/rector -- $rectorVerbosity process ${pathsToCheck[@]} \
#      --config "$projectRoot/rector.php" \
#      --clear-cache
#    rectorExitCode=$?
#    set -e
#    if ((rectorExitCode > 0)); then
#      tryAgainOrAbort "Rector Project Specific"
#    fi
#  done
#fi

