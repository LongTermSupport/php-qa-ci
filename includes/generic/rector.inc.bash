# Rector — automated refactoring (Safe-function conversion, PHPUnit upgrades, PHP 8.4).
#
# Rector MUTATES code. Whether it is allowed to write is governed by qaReadOnly
# (see detectReadOnly() in functions.inc.bash), NOT by CI:
#   - writable run  -> apply changes, retry loop on failure (historic behaviour)
#   - read-only run -> --dry-run; a pending change FAILS with remediation guidance
#     (used by GitHub Actions so the gate verifies instead of silently rewriting)

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

# Run one Rector config against one or more paths, honouring read-only mode.
#
# Usage: runRectorConfig <label> <configFile> <path> [<path>...]
#
# Exit codes are captured via an `if` condition so a non-zero status does not
# abort the run under errexit (no errexit toggling needed). Read-only dry-run
# codes (verified): 0 = clean, 2 = pending changes; any other non-zero is a
# genuine Rector error (bad config, parse failure).
function runRectorConfig() {
  local label="$1"
  local configFile="$2"
  shift 2

  local -a rectorArgs=(
    process
    --autoload-file "$projectRoot/vendor/autoload.php"
    --config "$configFile"
    --clear-cache
  )
  if [[ "true" == "${qaReadOnly:-false}" ]]; then
    rectorArgs+=(--dry-run)
  fi
  rectorArgs+=("$@")

  if [[ "true" == "${qaReadOnly:-false}" ]]; then
    # READ-ONLY: single pass, no retry (retrying a dry-run cannot change the result).
    echo "Running Rector ('$label') in read-only check mode"
    local readOnlyExit=0
    if rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity "${rectorArgs[@]}"; then
      readOnlyExit=0
    else
      readOnlyExit=$?
    fi
    if ((readOnlyExit == 0)); then
      return 0
    fi
    if ((readOnlyExit == 2)); then
      reportReadOnlyWouldModify "Rector ('$label')" "rector"
    fi
    echo "Rector ('$label') failed with exit code $readOnlyExit (a genuine error, not a pending-change diff) — see output above."
    exit 1
  fi

  # WRITABLE: apply changes, retry loop as before.
  local rectorExitCode=99
  while ((rectorExitCode > 1)); do
    echo "Running Rector ('$label')"
    if rectorIgnorePaths="$rectorIgnorePaths" phpNoXdebug -f "$rectorBin" -- $rectorVerbosity "${rectorArgs[@]}"; then
      rectorExitCode=0
    else
      rectorExitCode=$?
    fi
    if ((rectorExitCode > 0)); then
      tryAgainOrAbort "Rector '$label'"
    fi
  done
}

# First we run the Safe Rectors to implement safe versions of functions.
runRectorConfig "Safe" "$(configPath rector-safe.php)" "${pathsToCheck[@]}"

# Then PHPUnit Rector on the tests directory.
echo "Running PHPUnit Rector on $testsDir"
runRectorConfig "PHPUnit" "$(configPath rector-phpunit.php)" "$testsDir"

projectRectorFound=false
# Then we check for project specific Rectors.
for rectorConfig in "$projectRoot/rector.php" "$projectRoot/qaConfig/rector.php"; do
  if [[ -f $rectorConfig ]]; then
    projectRectorFound=true
    echo "Running Project Specific Rector as configured in $rectorConfig"
    runRectorConfig "Project Specific" "$rectorConfig" "${pathsToCheck[@]}"
  fi
done

if [[ $projectRectorFound == false ]]; then
  # Run PHP 8.4 specific rectors
  runRectorConfig "PHP 8.4" "$(configPath rector-php84.php)" "${pathsToCheck[@]}"
else
  echo "Skipping standard PHP 8.4 Rector as we assume its handled in project rector"
fi
