# First we run the Safe Rectors to implement safe versions of functions.

# Rector is installed in an isolated sub-composer project to prevent
# phpstan/phpstan leaking into the project's dependencies.
rectorBin="$qaDir/../tools/rector/vendor/bin/rector"
if [[ ! -f "$rectorBin" ]]; then
  echo "ERROR: Rector not found at $rectorBin"
  echo "Run: cd $qaDir/../tools/rector && composer install --no-dev"
  exit 1
fi

# Note: Rector does not support -v or -vv verbosity flags
# Using empty string for default verbosity level
rectorVerbosity=""
rectorIgnorePaths="";
if [[ "placeholder-ignore-item" != "${pathsToIgnore[*]}" ]]; then
  rectorIgnorePaths=$(printf '%s\n' "${pathsToIgnore[@]}")
fi


rectorSafeExitCode=99
while ((rectorSafeExitCode > 1)); do
  set +e
  echo "Running 'Safe' Rector to convert to safe versions of functions"
  rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity process \
    --autoload-file "$projectRoot/vendor/autoload.php" \
    --config "$(configPath rector-safe.php)" \
    --clear-cache \
    ${pathsToCheck[@]}
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
  rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity process \
    --autoload-file "$projectRoot/vendor/autoload.php" \
    --config "$(configPath rector-phpunit.php)" \
    --clear-cache \
    $testsDir
  rectorPhpUnitExitCode=$?
  set -e
  if ((rectorPhpUnitExitCode > 0)); then
    tryAgainOrAbort "Rector 'PHPUnit'"
  fi
done


projectRectorFound=false
# Then we check for project specific Rectors.
for rectorConfig in "$projectRoot/rector.php" "$projectRoot/qaConfig/rector.php";  do
  if [[ -f $rectorConfig ]]; then
    projectRectorFound=true
    rectorExitCode=99
    while ((rectorExitCode > 1)); do
      set +e
      echo "Running Project Specific Rector as configured in $rectorConfig"
      phpNoXdebug -f "$rectorBin" -- $rectorVerbosity process \
        --autoload-file "$projectRoot/vendor/autoload.php" \
        --config "$rectorConfig" \
        --clear-cache \
        ${pathsToCheck[@]}
      rectorExitCode=$?
      set -e
      if ((rectorExitCode > 0)); then
        tryAgainOrAbort "Rector Project Specific"
      fi
    done
  fi
done

if [[ $projectRectorFound == false ]]; then
  # Run PHP 8.4 specific rectors
  rectorPhp84ExitCode=99
  while ((rectorPhp84ExitCode > 1)); do
    set +e
    echo "Running PHP 8.4 Rector"
    rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity process \
      --autoload-file "$projectRoot/vendor/autoload.php" \
      --config "$(configPath rector-php84.php)" \
      --clear-cache \
      ${pathsToCheck[@]}
    rectorPhp84ExitCode=$?
    set -e
    if ((rectorPhp84ExitCode > 0)); then
      tryAgainOrAbort "Rector 'PHP 8.4'"
    fi
  done
else
  echo "Skipping standard PHP 8.4 Rector as we assuem its handled in project rector"
fi
