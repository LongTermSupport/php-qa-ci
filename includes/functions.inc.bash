#!/usr/bin/env bash

readonly platformGeneric="generic"
readonly platformSymfony="symfony"

function detectPlatform() {
  if [[ -f $projectRoot/symfony.lock ]]; then
    echo $platformSymfony
    return 0
  fi

  echo $platformGeneric
}

################################################################
# Run a tool
# First check for a project qaConfig tool override
# Then a platform tool
# finally the Generic tool
function runTool() {
  local tool="$1"
  local projectOverridePath="$projectConfigPath/tools/$tool.inc.bash"
  local platformPath="$DIR/../includes/$platform/$tool.inc.bash"
  local genericPath="$DIR/../includes/generic/$tool.inc.bash"

  if [[ -f "$projectOverridePath" ]]; then
    echo "Running Project Override $tool"
    source "$projectOverridePath"
    return 0
  elif [[ -f "$platformPath" ]]; then
    echo "Running $platform $tool"
    source "$platformPath"
    return 0
  fi
  echo "Running generic $tool"
  source "$genericPath"
}

# This is very much like run tool
# It will check for a project level override
# Then it will run the generic tool explicitly
function runNonPlatformTool() {
  local tool="$1"
  local projectOverridePath="$projectConfigPath/tools/$tool.inc.bash"
  local genericPath="$DIR/../includes/generic/$tool.inc.bash"

  if [[ -f "$projectOverridePath" ]]; then
    echo "Running Project Override $tool"
    source "$projectOverridePath"
    return 0
  fi
  echo "Running generic $tool"
  source "$genericPath"
}

################################################################
# Get the path for a config file
# Config file will be search for in:
#   A qaConfig folder in the project root
#   The phpqa library's configDefaults/{platform}
#   The phpqa library's configDefaults/generic
#
# Usage:
#
# `configPath "relative/path/to/file/or/folder"
function configPath() {
  local relativePath="$1"
  local platformPath="$defaultConfigPath/$platform/$relativePath"
  local genericPath="$defaultConfigPath/generic/$relativePath"
  # -e FILE - True if the FILE exists and is a file, regardless of type
  if [[ -e $projectConfigPath/$relativePath ]]; then
    echo $projectConfigPath/$relativePath
  elif [[ -e $platformPath ]]; then
    echo $platformPath
  else
    echo $genericPath
  fi
}

###############################################################
# Function to run PHP without Xdebug enabled, much faster
# Applies global memory limit (phpqaMemoryLimit) by default
# Usage:
# `phpNoXdebug path/to/php/file.php -- -arg1 -arg2`
# Note: Tool-specific memory limits can override by passing -d memory_limit=X
#       (last -d wins in PHP)
function phpNoXdebug() {
  if [[ ! -f ${noXdebugConfigPath} ]]; then
    # Using awk to ensure that files ending without newlines do not lead to configuration error
    ${phpBinPath} -i | grep "\.ini" | grep -o -e '\(/[a-z0-9._-]\+\)\+\.ini' | grep -v xdebug | xargs awk 'FNR==1{print ""}1' >"$noXdebugConfigPath"
  fi
  set -x
  # Apply global memory limit (can be overridden with explicit -d memory_limit=X after this)
  ${phpBinPath} -n -c "$noXdebugConfigPath" -d memory_limit=${phpqaMemoryLimit:-4G} "$@"
  local exitCode=$?
  set +x
  echo
  return $exitCode
}

###############################################################
# Function to check there are no uncommitted changes.
#
# This will also prompt you to commit these changes if you want to.
#
# Usage:
# checkForUncommitedChanges
function checkForUncommittedChanges() {
  if [[ "false" != "${CI:-'false'}" ]]; then
    echo "Skipping uncommited changes check in CI"
    return 0
  fi
  if [[ "$skipUncommittedChangesCheck" == "1" ]]; then
    echo "Skipping uncommitted changes check. export skipUncommittedChangesCheck=0 to reinstate"
    return 0
  fi

  targetDir=${1:-$(pwd)}
  originalDir=$(pwd)

  if [[ ! -d $targetDir/.git/ ]]; then
    echo "$targetDir is not a git repo"
    return
  fi

  cd $targetDir

  set +e
  inGitRepo="$(git rev-parse --is-inside-work-tree 2>/dev/null)"
  if [[ "" != "$inGitRepo" ]]; then
    git status | grep -Eq "nothing to commit, working .*? clean"
    repoDirty=$?
    set -e
    if (($repoDirty > 0)); then
      git status
      echo "

    ==================================================

        Untracked or Uncommited changes detected

        Would you like to commit (c) or abort (a)

        (git commit will be 'git add -A; git commit')

        Alternatively you can skip (s), but please be aware that from this point on, your code is going to be
        actively changed and not having a git commit to restore to could cause you significant pain.
        You have been warned.

        To always skip, you can export skipUncommittedChangesCheck=1

    ==================================================

            "
      read -n 1 commitOrAbort
      case "$commitOrAbort" in
      s)
        echo "Skipping"
        return 0
        ;;
      c)
        git add -A
        git commit
        ;;
      *)
        printf "\n\n\nAborting...\n\n\n"
        exit 1
        ;;
      esac
    fi
  fi

  cd $originalDir
}

function phpunitReRunFailedOrFull() {
  if [[ "false" != "${CI:-'false'}" ]]; then
    return 0
  fi
  local rerunFailed
  local reunLogFileTimeLimit=${phpunitRerunTimeoutMins:-5}
  local rerunLogFile="$(find $varDir -type f -name 'phpunit.junit.xml' -mmin -$reunLogFileTimeLimit)"
  if [[ "" == "$rerunLogFile" ]]; then
    echo ""
    return 1
  fi
  echo "

    ==================================================

        PHPUnit Run detected from less than $reunLogFileTimeLimit mins ago

        Would you like to just rerun failed tests?

        (will timeout and run full in 10 seconds)

    ==================================================

        "
  set +e
  read -t 10 -n 1 rerunFailed
  set -e
  if [[ "y" != "$rerunFailed" ]]; then
    printf "\n\nRunning Full...\n\n"
    return 1
  fi
  printf "\n\nRerunning Failed Only\n\n"
  return 0
}

function tryAgainOrAbort() {
  toolname="$1"
  if [[ "false" != "${CI:-'false'}" ]]; then
    echo "

    ==================================================

        $toolname Failed...

    ==================================================

        "
    exit 1
  fi
  echo "

    ==================================================

        $toolname Failed...

        would you like to try again? (y/n)

        (note: if you change config files, you might have to run from the top for it to take effect...)

    ==================================================

    "
  while read -n 1 tryAgainOrAbort; do
    if [[ "n" == "$tryAgainOrAbort" ]]; then
      printf "\n\nAborting...\n\n"
      exit 1
    fi
    if [[ "y" == "$tryAgainOrAbort" ]]; then
      break
    fi
    printf "\n\ninvalid choice: $tryAgainOrAbort - should be y or n \n\n        would you like to try again? (y/n)"
  done
  printf "\n\nTrying again, good luck!\n\n"
  hasBeenRestarted="true"
}

function findTestsDir() {
  testsDir="$(find $projectRoot -maxdepth 1 -type d \( -name test -o -name tests \) | head -n1)"
  if [[ "" == "$testsDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'tests' or 'test' directory.

    This is not currently supported by phpqa

    Please create at least an empty 'tests' directory, eg:

    mkdir -p $projectRoot/tests


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$testsDir"
}

function findSrcDir() {
  srcDir="$projectRoot/src"
  if [[ "" == "$srcDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'src' or directory.

    This is not currently supported by phpqa

    Please create at least an empty 'src' directory, eg:

    mkdir -p $projectRoot/src


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$srcDir"
}

function findBinDir() {
  binDir="$(cd $projectRoot && composer config bin-dir)"
  if [[ "" == "$binDir" ]]; then
    echo "


    ##### ERROR ############################################


    You have no 'bin' or directory.

    This is not currently supported by phpqa

    Please create at least an empty 'bin' directory, eg:

    mkdir -p $projectRoot/bin


    ########################################################

        " 1>&2
    exit 1
  fi
  echo "$binDir"
}

###############################################################
# Archive and rotate QA tool log files with retention policy
#
# This function provides standardized log rotation for QA tools:
# - Archives current log with timestamp
# - Separates full suite runs from path-specific runs
# - Keeps last 10 logs per pattern (full suite + each unique path)
# - Warns when total logs exceed 100 files
#
# Usage:
#   archiveToolLog "toolname" "$logDir" "logfile.ext" "$specifiedPath" "${pathsToCheck[@]}"
#
# Parameters:
#   $1 - toolName: Name of the tool (e.g., "phpunit", "phpstan")
#   $2 - logDir: Directory where logs are stored
#   $3 - logFileName: Base name of the log file (e.g., "phpunit.junit.xml")
#   $4 - specifiedPath: Non-empty if -p flag was used
#   $5+ - pathsToCheck: Array of paths that were checked
#
# Examples:
#   # Full suite run
#   archiveToolLog "phpunit" "$varDir/phpunit_logs" "phpunit.junit.xml" "" "${pathsToCheck[@]}"
#   # Creates: phpunit.junit.YYYYMMDD-HHMMSS.xml
#
#   # Path-specific run
#   archiveToolLog "phpstan" "$varDir/phpstan_logs" "phpstan.json" "1" "src/Entity tests/Unit"
#   # Creates: phpstan.json.src_Entity_tests_Unit.YYYYMMDD-HHMMSS.xml
function archiveToolLog() {
    local toolName="$1"
    local logDir="$2"
    local logFileName="$3"
    local specifiedPath="$4"
    shift 4
    local pathsToCheck=("$@")

    local logFilePath="$logDir/$logFileName"

    # Only archive if log file exists
    if [[ ! -f "$logFilePath" ]]; then
        return 0
    fi

    local timestamp=$(date +"%Y%m%d-%H%M%S")
    local baseFileName="${logFileName%.*}"  # Remove extension
    local extension="${logFileName##*.}"    # Get extension

    # Determine log file naming based on whether specific paths were specified
    if [[ -n "$specifiedPath" ]]; then
        # Path-specific run - generate suffix from paths
        local pathSuffix=$(echo "${pathsToCheck[*]}" | tr -cs '[:alnum:]' '_' | sed 's/^_//; s/_$//')
        local archivedLog="$logDir/${baseFileName}.${pathSuffix}.${timestamp}.${extension}"
        local runType="Path-specific run: ${pathsToCheck[*]}"

        # Match only logs for this specific path suffix
        local archivedLogs=($(ls -1t "$logDir"/${baseFileName}.*.${extension} 2>/dev/null | grep "${baseFileName}\\.${pathSuffix}\\."))
    else
        # Full suite run
        local archivedLog="$logDir/${baseFileName}.${timestamp}.${extension}"
        local runType="Full test suite run"

        # Match only full suite logs (timestamp directly after base name)
        local archivedLogs=($(ls -1t "$logDir"/${baseFileName}.*.${extension} 2>/dev/null | grep -E "${baseFileName}\\.[0-9]{8}-[0-9]{6}\\.${extension}$"))
    fi

    # Archive with clear messaging
    # Use cp (not mv) so the live log stays at its known filename for any
    # downstream tool that reads it (e.g. infection consumes phpunit.junit.xml).
    # The timestamped copy is the rotated archive; the live file is the latest.
    cp "$logFilePath" "$archivedLog"
    echo "${runType}"
    echo "Log: $(basename "$archivedLog")"

    # Keep only last 10 logs matching this pattern
    local numLogs=${#archivedLogs[@]}
    if (( numLogs > 10 )); then
        for ((i=10; i<numLogs; i++)); do
            rm -f "${archivedLogs[$i]}"
        done
        echo "Cleaned up $((numLogs - 10)) old log(s)"
    fi

    # Check total log count across all patterns and warn if > 100
    # Use a marker file to track if we've already warned for this logDir in this run
    local warnMarkerFile="$logDir/.warned_high_count_$$"
    local totalLogs=$(find "$logDir" -name "*.${extension}" -type f 2>/dev/null | wc -l)

    if (( totalLogs > 100 )) && [[ ! -f "$warnMarkerFile" ]]; then
        # Create marker to prevent duplicate warnings
        touch "$warnMarkerFile"

        echo ""
        echo "=========================================="
        echo "WARNING: HIGH LOG FILE COUNT"
        echo "=========================================="
        echo "Total log files in $logDir: $totalLogs"
        echo ""
        echo "Consider manual cleanup of old logs:"
        echo "  ls -lht $logDir | less"
        echo ""
        echo "To remove logs for specific paths:"
        echo "  rm $logDir/${baseFileName}.PATH_PATTERN.*.${extension}"
        echo ""
        echo "Retention: 10 per pattern (full suite + each -p path)"
        echo "=========================================="
    fi
}
